<?php

namespace Tests\Feature;

use App\Analytics\Aggregation\Aggregator;
use App\Installation\InstallationState;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AnalyticsMvpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
    }

    private function event(Website $site, string $date, ?string $session = null, array $extra = []): int
    {
        return DB::table('events')->insertGetId(array_merge(['website_id' => $site->id, 'received_at' => $date, 'path' => '/pricing', 'consent_given' => true, 'session_key' => $session, 'tracking_mode' => $session ? 'sessions' : 'pageviews'], $extra));
    }

    public function test_expired_pending_events_survive_cleanup(): void
    {
        $site = Website::factory()->create();
        $pending = $this->event($site, now()->subDays(100)->toDateTimeString());
        $processed = $this->event($site, now()->subDays(100)->toDateTimeString(), extra: ['processed_at' => now()]);
        $this->artisan('perysee:prune-tracking')->assertSuccessful();
        $this->assertDatabaseHas('events', ['id' => $pending]);
        $this->assertDatabaseMissing('events', ['id' => $processed]);
    }

    public function test_timezone_is_locked_after_first_successful_collection(): void
    {
        $owner = User::factory()->create();
        $site = Website::factory()->create(['timezone' => 'UTC']);
        $site->members()->attach($owner, ['role' => 'owner']);
        $this->withHeader('Origin', 'https://'.$site->domain)->postJson('/api/collect', ['v' => 1, 'site' => $site->public_id, 'type' => 'pageview', 'mode' => 'pageviews', 'host' => $site->domain, 'path' => '/', 'consent' => true])->assertNoContent();
        $this->assertNotNull($site->fresh()->first_collected_at);
        $this->actingAs($owner)->put('/sites/'.$site->id, ['name' => $site->name, 'domain' => $site->domain, 'allowed_domains' => [], 'timezone' => 'Europe/Paris'])->assertSessionHasErrors('timezone');
        $this->assertSame('UTC', $site->fresh()->timezone);
    }

    public function test_aggregation_is_replayable_and_sessions_start_only_once_across_midnight(): void
    {
        $site = Website::factory()->create(['tracking_mode' => 'sessions', 'timezone' => 'Europe/Paris']);
        $key = str_repeat('a', 64);
        $this->event($site, '2026-10-23 21:59:00', $key);
        $this->event($site, '2026-10-23 22:01:00', $key);
        $this->assertSame(2, app(Aggregator::class)->run(1, 15));
        $this->assertSame(0, app(Aggregator::class)->run(1, 15));
        $this->assertDatabaseHas('stats_daily', ['website_id' => $site->id, 'stat_date' => '2026-10-23', 'pageviews' => 1, 'sessions' => 1]);
        $this->assertDatabaseHas('stats_daily', ['website_id' => $site->id, 'stat_date' => '2026-10-24', 'pageviews' => 1, 'sessions' => 0]);
        $this->assertSame(2, (int) DB::table('stats_hourly')->sum('pageviews'));
    }

    public function test_late_visible_event_repositions_the_session_start(): void
    {
        $site = Website::factory()->create(['tracking_mode' => 'sessions', 'timezone' => 'UTC']);
        $key = str_repeat('b', 64);
        $this->event($site, '2026-10-24 00:01:00', $key);
        app(Aggregator::class)->run();
        $this->event($site, '2026-10-23 23:59:00', $key);
        app(Aggregator::class)->run();
        $this->assertDatabaseHas('stats_daily', ['stat_date' => '2026-10-24', 'sessions' => 0]);
        $this->assertDatabaseHas('stats_daily', ['stat_date' => '2026-10-23', 'sessions' => 1]);
        $this->assertSame(1, (int) DB::table('stats_daily')->sum('sessions'));
    }

    public function test_failed_batch_rolls_back_counters_and_processed_markers(): void
    {
        $site = Website::factory()->create();
        $id = $this->event($site, '2026-10-24 10:00:00');
        DB::listen(function ($query): void {
            if (str_contains($query->sql, 'insert') && str_contains($query->sql, 'stats_dimension_daily')) {
                throw new \RuntimeException('Injected interruption after dimensions write.');
            }
        });
        try {
            app(Aggregator::class)->run();
            $this->fail('The missing table must abort the transaction.');
        } catch (\RuntimeException) {
            $this->assertNull(DB::table('events')->where('id', $id)->value('processed_at'));
            $this->assertSame(0, DB::table('stats_daily')->count());
        }
    }

    public function test_custom_event_and_safe_dimensions_are_collected(): void
    {
        $site = Website::factory()->create();
        $this->withHeaders(['Origin' => 'https://'.$site->domain, 'User-Agent' => 'Mozilla/5.0 (iPhone)'])->postJson('/api/collect', [
            'v' => 1, 'site' => $site->public_id, 'type' => 'event', 'event_name' => 'signup', 'mode' => 'pageviews', 'host' => $site->domain,
            'path' => '/thanks', 'consent' => true, 'ref' => 'https://example.org/path?email=secret@example.com', 'utm_source' => 'newsletter',
        ])->assertNoContent();
        $this->assertDatabaseHas('events', ['event_type' => 2, 'event_name' => 'signup', 'referrer_host' => 'example.org', 'device_type' => 'mobile', 'utm_source' => 'newsletter']);
        app(Aggregator::class)->run();
        $this->assertSame(0, (int) DB::table('stats_daily')->sum('pageviews'));
        $this->assertSame(1, (int) DB::table('stats_daily')->sum('custom_events'));
    }

    public function test_reporting_api_csv_and_permissions_use_the_same_totals(): void
    {
        $owner = User::factory()->create();
        $site = Website::factory()->create(['timezone' => 'UTC']);
        $site->members()->attach($owner, ['role' => 'owner']);
        $this->event($site, '2026-10-24 10:00:00');
        app(Aggregator::class)->run();
        $query = '?from=2026-10-24&to=2026-10-24';
        $this->actingAs($owner)->getJson('/api/sites/'.$site->id.'/overview'.$query)->assertOk()->assertJsonPath('totals.pageviews', 1)->assertJsonPath('totals.sessions', null);
        $response = $this->get('/sites/'.$site->id.'/export'.$query)->assertOk();
        $this->assertStringContainsString('2026-10-24,1', $response->streamedContent());
        $this->get('/sites/'.$site->id.$query)->assertOk()->assertInertia(fn (Assert $page) => $page->where('screen', 'sites.show')->where('report.totals.pageviews', 1)->where('report.totals.sessions', null));
        $this->actingAs(User::factory()->create(['is_admin' => true]))->getJson('/api/sites/'.$site->id.'/overview'.$query)->assertForbidden();
        $this->get('/sites/'.$site->id.'/export'.$query)->assertForbidden();
    }

    public function test_health_is_admin_only_and_site_deletion_is_bounded_and_resumable(): void
    {
        $owner = User::factory()->create();
        $site = Website::factory()->create();
        $site->members()->attach($owner, ['role' => 'owner']);
        $this->event($site, now()->toDateTimeString());
        $this->actingAs($owner)->get('/health')->assertForbidden();
        $this->delete('/sites/'.$site->id, ['confirmation' => $site->domain])->assertRedirect('/sites');
        $this->assertNotNull($site->fresh()->deleting_at);
        $this->withHeader('Origin', 'https://'.$site->domain)->postJson('/api/collect', ['v' => 1, 'site' => $site->public_id, 'type' => 'pageview', 'mode' => 'pageviews', 'host' => $site->domain, 'path' => '/', 'consent' => true])->assertNotFound();
        $this->artisan('perysee:maintenance', ['--limit' => 1])->assertSuccessful();
        $this->artisan('perysee:maintenance', ['--limit' => 1])->assertSuccessful();
        $this->assertDatabaseMissing('websites', ['id' => $site->id]);
        $this->assertDatabaseMissing('events', ['website_id' => $site->id]);
        $this->actingAs(User::factory()->create(['is_admin' => true]))->get('/health')->assertOk();
    }
}
