<?php

namespace Tests\Feature;

use App\Analytics\Aggregation\Aggregator;
use App\Analytics\Ingestion\ProbeToken;
use App\Installation\InstallationState;
use App\Models\User;
use App\Models\Website;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class TrackerProbeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
    }

    private function payload(Website $site, array $extra = []): array
    {
        return array_merge(['v' => 1, 'site' => $site->public_id, 'type' => 'pageview', 'mode' => 'pageviews', 'host' => $site->domain, 'path' => '/', 'consent' => true], $extra);
    }

    /** The probe comes from the administration, an origin that is not an allowed domain of the site. */
    private function probe(Website $site, ?string $token, array $extra = [])
    {
        $headers = ['Origin' => 'https://admin.example'] + ($token === null ? [] : [ProbeToken::HEADER => $token]);

        return $this->withHeaders($headers)->postJson('/api/collect', $this->payload($site, $extra));
    }

    private function token(Website $site): string
    {
        return app(ProbeToken::class)->issue($site->public_id)['token'];
    }

    public function test_a_signed_probe_is_acknowledged_without_storing_an_event(): void
    {
        $site = Website::factory()->create();
        $token = $this->token($site);

        $this->probe($site, $token)->assertNoContent()->assertHeader(ProbeToken::HEADER, $token)->assertHeader('Access-Control-Allow-Origin', '*')->assertHeader('Access-Control-Expose-Headers', ProbeToken::HEADER);

        $this->assertSame(0, DB::table('events')->count());
        $this->assertNull($site->fresh()->first_collected_at);
        $this->assertSame(1, DB::table('collection_diagnostics')->where('website_id', $site->id)->where('cause', 'probe_ok')->count());
    }

    public function test_a_probe_never_reaches_the_statistics(): void
    {
        $site = Website::factory()->create();
        $this->probe($site, $this->token($site))->assertNoContent();

        app(Aggregator::class)->run();

        $this->assertSame(0, (int) DB::table('stats_daily')->sum('pageviews'));
        $this->assertSame(0, DB::table('events')->count());
    }

    public function test_repeated_probes_share_one_row(): void
    {
        $site = Website::factory()->create();
        foreach (range(1, 3) as $ignored) {
            $this->probe($site, $this->token($site))->assertNoContent();
        }

        $this->assertSame(3, (int) DB::table('collection_diagnostics')->where('website_id', $site->id)->where('cause', 'probe_ok')->value('hits'));
    }

    public function test_without_a_valid_token_the_usual_rules_apply(): void
    {
        $site = Website::factory()->create();
        $other = Website::factory()->create();
        $expired = now('UTC')->timestamp - 5;
        $forged = $expired.'.'.hash_hmac('sha256', 'probe|'.$site->public_id.'|'.$expired, 'not-the-key');

        foreach ([null, 'garbage', $this->token($other), $forged, $expired.'.'.str_repeat('a', 64)] as $token) {
            $this->probe($site, $token)->assertForbidden();
        }
        $this->assertSame(0, DB::table('collection_diagnostics')->where('cause', 'probe_ok')->count());
        $this->assertSame(0, DB::table('events')->count());
    }

    public function test_an_expired_token_is_refused(): void
    {
        $site = Website::factory()->create();
        $token = $this->token($site);
        $this->travel(11)->minutes();

        $this->probe($site, $token)->assertForbidden();
    }

    public function test_a_token_cannot_be_issued_for_a_far_future(): void
    {
        $this->assertFalse(app(ProbeToken::class)->valid((now('UTC')->timestamp + 86400).'.'.str_repeat('a', 64), 'x'));
    }

    public function test_a_probe_still_needs_a_well_formed_payload_and_an_existing_site(): void
    {
        $site = Website::factory()->create();
        $token = $this->token($site);

        $this->probe($site, $token, ['v' => 2])->assertStatus(422);
        $this->probe($site, $token, ['site' => '99999999-9999-4999-8999-999999999999'])->assertNotFound();
        DB::table('websites')->where('id', $site->id)->update(['deleting_at' => now('UTC')]);
        $this->probe($site, $token)->assertNotFound();
    }

    public function test_a_database_failure_during_the_probe_is_not_acknowledged(): void
    {
        $site = Website::factory()->create();
        // No DDL here: on MariaDB it would end the test transaction and destroy the shared table.
        Event::listen(QueryExecuted::class, function ($query): void {
            if (str_contains($query->sql, 'collection_diagnostics')) {
                throw new \RuntimeException('diagnostics are unavailable');
            }
        });

        $this->probe($site, $this->token($site))->assertStatus(503);
    }

    public function test_probes_are_rate_limited_per_site_and_do_not_use_the_visitor_quota(): void
    {
        $site = Website::factory()->create();
        foreach (range(1, 12) as $ignored) {
            $this->probe($site, $this->token($site))->assertNoContent();
        }
        $this->probe($site, $this->token($site))->assertStatus(429);

        $this->assertFalse(RateLimiter::tooManyAttempts('collect:'.$site->id.':'.hash_hmac('sha256', '127.0.0.1', config('app.key')), 1));
        $this->withoutHeader(ProbeToken::HEADER)->withHeader('Origin', 'https://'.$site->domain)->postJson('/api/collect', $this->payload($site))->assertNoContent();
    }

    public function test_the_nominal_path_has_the_same_cost_with_a_foreign_probe_header(): void
    {
        $site = Website::factory()->create(['first_collected_at' => now('UTC')]);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });
        $this->withHeaders(['Origin' => 'https://'.$site->domain, ProbeToken::HEADER => 'not-a-token'])->postJson('/api/collect', $this->payload($site))->assertNoContent();

        $statements = array_values(array_filter($queries, fn (string $sql): bool => ! preg_match('/^(begin|commit|rollback|savepoint|release)/', $sql)));
        $this->assertCount(2, $statements, implode(' | ', $queries));
        $this->assertSame(1, DB::table('events')->count());
    }

    public function test_the_preflight_lets_another_origin_send_the_probe_header(): void
    {
        $this->call('OPTIONS', '/api/collect')->assertNoContent()->assertHeader('Access-Control-Allow-Origin', '*')
            ->assertHeader('Access-Control-Allow-Headers', 'Content-Type, '.ProbeToken::HEADER)->assertHeader('Access-Control-Allow-Methods', 'POST');
    }

    public function test_the_owner_gets_a_token_that_the_collector_accepts(): void
    {
        $site = Website::factory()->create();
        $owner = User::factory()->create();
        $site->members()->attach($owner, ['role' => 'owner']);

        $issued = $this->actingAs($owner)->postJson('/sites/'.$site->id.'/tracking/probe')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonStructure(['token', 'expires_at', 'site', 'mode', 'host'])->json();

        $this->assertSame($site->public_id, $issued['site']);
        $this->probe($site, $issued['token'])->assertNoContent();
    }

    public function test_the_token_endpoint_is_throttled(): void
    {
        $site = Website::factory()->create();
        $owner = User::factory()->create();
        $site->members()->attach($owner, ['role' => 'owner']);
        $this->actingAs($owner);
        foreach (range(1, 6) as $ignored) {
            $this->postJson('/sites/'.$site->id.'/tracking/probe')->assertOk();
        }

        $this->postJson('/sites/'.$site->id.'/tracking/probe')->assertStatus(429);
    }
}
