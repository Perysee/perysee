<?php

namespace Tests\Feature;

use App\Installation\InstallationState;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class TrackingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
    }

    private function payload(Website $site, array $extra = []): array
    {
        return array_merge(['v' => 1, 'site' => $site->public_id, 'type' => 'pageview', 'mode' => 'pageviews', 'host' => $site->domain, 'path' => '/pricing?email=a@example.com#token', 'consent' => true], $extra);
    }

    public function test_pageview_is_persisted_without_session_ip_or_cookies(): void
    {
        $site = Website::factory()->create();
        $response = $this->withHeader('Origin', 'https://'.$site->domain)->postJson('/api/collect', $this->payload($site))->assertNoContent()->assertHeader('Access-Control-Allow-Origin', '*');
        $this->assertEmpty($response->headers->getCookies());
        $event = DB::table('events')->first();
        $this->assertSame('/pricing', $event->path);
        $this->assertNull($event->session_key);
        $this->assertObjectNotHasProperty('ip_address', $event);
        $this->assertObjectNotHasProperty('user_agent', $event);
    }

    public function test_sessions_are_hashed_and_same_token_remains_the_same_for_one_site_only(): void
    {
        $a = Website::factory()->create(['tracking_mode' => 'sessions']);
        $b = Website::factory()->create(['tracking_mode' => 'sessions']);
        $token = str_repeat('a', 32);
        foreach ([$a, $a, $b] as $site) {
            $this->withHeader('Origin', 'https://'.$site->domain)->postJson('/api/collect', $this->payload($site, ['mode' => 'sessions', 'session' => $token]))->assertNoContent();
        }
        $keys = DB::table('events')->pluck('session_key');
        $this->assertSame($keys[0], $keys[1]);
        $this->assertNotSame($keys[0], $keys[2]);
        $this->assertNotSame($token, $keys[0]);
    }

    public function test_policy_is_enforced_by_the_server(): void
    {
        $site = Website::factory()->create(['tracking_mode' => 'sessions']);
        $this->withHeader('Origin', 'https://'.$site->domain);
        $this->postJson('/api/collect', $this->payload($site, ['mode' => 'sessions', 'consent' => false, 'session' => str_repeat('a', 32)]))->assertForbidden();
        $this->postJson('/api/collect', $this->payload($site))->assertUnprocessable();
        $this->postJson('/api/collect', $this->payload($site, ['mode' => 'sessions']))->assertUnprocessable();
        $site->update(['consent_required' => false]);
        $this->postJson('/api/collect', $this->payload($site, ['mode' => 'sessions', 'consent' => false, 'session' => str_repeat('a', 32)]))->assertNoContent();
        $this->assertDatabaseCount('events', 1);
    }

    public function test_domains_payload_sizes_and_sensitive_fields_are_rejected(): void
    {
        $site = Website::factory()->create();
        $this->withHeader('Origin', 'https://outsider.example')->postJson('/api/collect', $this->payload($site))->assertForbidden();
        $this->withHeader('Origin', 'https://'.$site->domain)->postJson('/api/collect', $this->payload($site, ['email' => 'private@example.com']))->assertUnprocessable();
        $this->postJson('/api/collect', $this->payload($site, ['session' => str_repeat('a', 32)]))->assertUnprocessable();
        $this->postJson('/api/collect', $this->payload($site, ['path' => str_repeat('a', 9000)]))->assertStatus(413);
        $this->assertDatabaseCount('events', 0);
    }

    public function test_beacon_compatible_plain_text_is_supported_and_malformed_json_is_rejected(): void
    {
        $site = Website::factory()->create();
        $server = ['CONTENT_TYPE' => 'text/plain', 'HTTP_ORIGIN' => 'https://'.$site->domain];
        $this->call('POST', '/api/collect', server: $server, content: json_encode($this->payload($site)))->assertNoContent();
        $this->call('POST', '/api/collect', server: $server, content: '{bad')->assertStatus(422);
    }

    public function test_tracking_script_negotiates_gzip_without_starting_a_session(): void
    {
        $plain = $this->get('/lueur.js')->assertOk()->assertHeader('Content-Type', 'application/javascript; charset=UTF-8');
        $compressed = $this->withHeader('Accept-Encoding', 'gzip')->get('/lueur.js')->assertOk()->assertHeader('Content-Encoding', 'gzip')->assertHeader('Vary', 'Accept-Encoding');
        $this->assertSame($plain->getContent(), gzdecode($compressed->getContent()));
        $this->assertLessThan(5120, strlen($compressed->getContent()));
        $this->withHeader('Accept-Encoding', 'gzip;q=0')->get('/lueur.js')->assertHeaderMissing('Content-Encoding');
        $this->assertEmpty($compressed->headers->getCookies());
    }

    public function test_sql_failure_is_not_acknowledged_as_success(): void
    {
        $site = Website::factory()->create();
        DB::shouldReceive('table')->with('events')->andThrow(new \RuntimeException('private SQL failure'));
        $this->withHeader('Origin', 'https://'.$site->domain)->postJson('/api/collect', $this->payload($site))->assertStatus(503)->assertDontSee('private SQL failure');
    }

    public function test_client_cannot_bypass_path_redaction_and_rate_limits(): void
    {
        $site = Website::factory()->create();
        $this->withHeader('Origin', 'https://'.$site->domain)->postJson('/api/collect', $this->payload($site, ['path' => '/users/123/a%40example.com']))->assertNoContent();
        $this->assertDatabaseHas('events', ['path' => '/users/:redacted/:redacted']);
        $key = 'collect:'.$site->id.':'.hash_hmac('sha256', '127.0.0.1', config('app.key'));
        RateLimiter::increment($key, 60, 120);
        $this->postJson('/api/collect', $this->payload($site))->assertStatus(429);
        $this->assertDatabaseCount('events', 1);
    }
}
