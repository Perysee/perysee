<?php

namespace Tests\Feature;

use App\Installation\InstallationState;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Freezes the status code and the JSON body of every collector rejection, so that the cause bookkeeping can
 * be added without changing what a tracker or a browser sees.
 */
class CollectionRejectionCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
    }

    private function payload(Website $site, array $extra = []): array
    {
        return array_merge(['v' => 1, 'site' => $site->public_id, 'type' => 'pageview', 'mode' => 'pageviews', 'host' => $site->domain, 'path' => '/pricing', 'consent' => true], $extra);
    }

    private function send(Website $site, array $extra = [], ?string $origin = null)
    {
        return $this->withHeader('Origin', $origin ?? 'https://'.$site->domain)->postJson('/api/collect', $this->payload($site, $extra));
    }

    public function test_every_rejection_keeps_its_status_and_body(): void
    {
        $site = Website::factory()->create();
        $this->send($site, ['site' => '11111111-1111-4111-8111-111111111111'])->assertStatus(404)->assertExactJson(['message' => '']);
        $this->send($site, [], 'https://outsider.example')->assertStatus(403)->assertExactJson(['message' => '']);
        $this->send($site, ['host' => 'other.example'])->assertStatus(403);
        $this->send($site, ['consent' => false])->assertStatus(403);
        $this->send($site, ['mode' => 'sessions', 'session' => str_repeat('a', 32)])->assertStatus(422);
        $this->send($site, ['session' => str_repeat('a', 32)])->assertStatus(422);
        $this->send($site, ['v' => 2])->assertStatus(422)->assertJsonValidationErrors(['v']);
        $this->send($site, ['email' => 'a@example.com'])->assertStatus(422);
        $this->send($site, ['path' => str_repeat('a', 9000)])->assertStatus(413);
        $this->call('POST', '/api/collect', server: ['CONTENT_TYPE' => 'text/html', 'HTTP_ORIGIN' => 'https://'.$site->domain], content: '{}')->assertStatus(415);
        $this->call('POST', '/api/collect', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'https://'.$site->domain], content: '[1')->assertStatus(422);
    }

    public function test_excluded_paths_deleted_sites_and_the_rate_limit_keep_their_status(): void
    {
        $site = Website::factory()->create(['excluded_paths' => ['/admin*']]);
        $this->send($site, ['path' => '/admin/users'])->assertForbidden();
        RateLimiter::increment('collect:'.$site->id.':'.hash_hmac('sha256', '127.0.0.1', config('app.key')), 60, 120);
        $this->send($site)->assertStatus(429);
        $gone = Website::factory()->create(['deleting_at' => now('UTC')]);
        $this->send($gone)->assertStatus(404);
    }

    public function test_an_uninstalled_instance_answers_503(): void
    {
        $site = Website::factory()->create();
        $this->mock(InstallationState::class)->shouldReceive('isLocked')->andReturn(false);
        $this->send($site)->assertStatus(503);
    }
}
