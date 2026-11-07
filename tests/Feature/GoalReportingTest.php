<?php

namespace Tests\Feature;

use App\Analytics\Aggregation\Aggregator;
use App\Installation\InstallationState;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GoalReportingTest extends TestCase
{
    use RefreshDatabase;

    private Website $site;

    private User $owner;

    private int $goal;

    protected function setUp(): void
    {
        parent::setUp();
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
        $this->travelTo(now('UTC')->setDate(2026, 10, 26)->setTime(12, 0));
        $this->site = Website::factory()->create(['tracking_mode' => 'sessions']);
        $this->owner = User::factory()->create();
        $this->site->members()->attach($this->owner, ['role' => 'owner']);
        $this->goal = DB::table('goals')->insertGetId(['website_id' => $this->site->id, 'name' => 'Signup', 'current_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('goal_versions')->insert(['website_id' => $this->site->id, 'goal_id' => $this->goal, 'version' => 1, 'type' => 'event', 'value' => 'signup', 'threshold' => 1, 'created_at' => now('UTC')->subDays(30)]);
    }

    private function event(string $session, string $time, array $extra = [], int $type = 1): int
    {
        return DB::table('events')->insertGetId($extra + ['website_id' => $this->site->id, 'received_at' => '2026-10-24 '.$time, 'event_type' => $type, 'event_name' => $type === 2 ? 'signup' : null, 'path' => '/', 'tracking_mode' => 'sessions', 'session_key' => $session,
            'country_code' => 'FR', 'geo_available' => true, 'device_type' => 'mobile', 'dimensions_available' => true, 'source_value' => 'chatgpt', 'consent_given' => true]);
    }

    private function report(string $query = ''): array
    {
        return $this->actingAs($this->owner)->getJson('/api/sites/'.$this->site->id.'/goals/'.$this->goal.'?from=2026-10-24&to=2026-10-26'.$query)->assertOk()->json();
    }

    public function test_rate_uses_measured_sessions_and_is_null_when_sessions_are_missing(): void
    {
        $this->event('s1', '10:00:00');
        $this->event('s1', '10:01:00', [], 2);
        $this->event('s2', '11:00:00');
        $this->event('s3', '12:00:00');
        $this->event('s3', '12:01:00', [], 2);
        $this->event('s4', '13:00:00');
        app(Aggregator::class)->run();

        $report = $this->report();
        $this->assertSame('sessions', $report['mode']);
        $this->assertSame(['conversions' => 2, 'hits' => 2, 'sessions' => 4, 'rate' => 50], $report['totals']);
        $this->assertSame('available', $report['coverage']['status']);
        $day = collect($report['series'])->firstWhere('date', '2026-10-24');
        $this->assertSame([2, 4, 50], [$day['conversions'], $day['sessions'], $day['rate']]);

        DB::table('measured_sessions')->insert(['website_id' => $this->site->id, 'session_key' => 'orphan', 'first_seen_at' => '2026-10-24 14:00:00', 'last_seen_at' => '2026-10-24 14:00:00']);
        $report = $this->report();
        $this->assertNull($report['totals']['rate']);
        $this->assertNull($report['totals']['sessions']);
        $this->assertSame(2, $report['totals']['conversions']);
        $this->assertSame('partial', $report['coverage']['status']);
        $this->assertSame(1, $report['coverage']['missing_sessions']);
    }

    public function test_sites_without_sessions_show_hits_and_no_rate(): void
    {
        $this->site->update(['tracking_mode' => 'pageviews']);
        DB::table('events')->insert(['website_id' => $this->site->id, 'received_at' => '2026-10-24 10:00:00', 'event_type' => 2, 'event_name' => 'signup', 'path' => '/', 'tracking_mode' => 'pageviews', 'consent_given' => true]);
        app(Aggregator::class)->run();

        $report = $this->report();

        $this->assertSame('pageviews', $report['mode']);
        $this->assertSame(['conversions' => null, 'hits' => 1, 'sessions' => null, 'rate' => null], $report['totals']);
    }

    public function test_breakdown_uses_first_event_of_the_session(): void
    {
        $this->event('s1', '10:00:00');
        $this->event('s1', '10:05:00', ['country_code' => 'DE', 'device_type' => 'desktop'], 2);
        $this->event('s2', '11:00:00', ['country_code' => 'DE', 'device_type' => 'desktop', 'source_value' => 'google']);
        $this->event('s2', '11:05:00', [], 2);
        $this->event('s3', '12:00:00', ['country_code' => 'DE', 'device_type' => 'desktop', 'source_value' => 'google']);
        app(Aggregator::class)->run();

        $report = $this->report();
        $countries = collect($report['breakdown']['country'])->keyBy('value');
        $this->assertSame([1, 1, 100], [$countries['FR']['conversions'], $countries['FR']['sessions'], $countries['FR']['rate']]);
        $this->assertSame([1, 2, 50], [$countries['DE']['conversions'], $countries['DE']['sessions'], $countries['DE']['rate']]);
        $this->assertEqualsCanonicalizing(['chatgpt.com', 'google'], array_column($report['breakdown']['source'], 'value'));
        $this->assertEqualsCanonicalizing(['mobile', 'desktop'], array_column($report['breakdown']['device'], 'value'));

        $filtered = $this->report('&filters[country][]=FR');
        $this->assertSame(['conversions' => 1, 'hits' => 1, 'sessions' => 1, 'rate' => 100], $filtered['totals']);
        $this->assertSame(['FR'], array_column($filtered['breakdown']['country'], 'value'));

        // An earlier first event that arrives later takes the attribution with it.
        $this->event('s1', '09:00:00', ['country_code' => 'US']);
        app(Aggregator::class)->run();
        $countries = collect($this->report()['breakdown']['country'])->keyBy('value');
        $this->assertSame(1, $countries['US']['conversions']);
        $this->assertArrayNotHasKey('FR', $countries->all());
        $this->assertSame(2, $this->report()['totals']['conversions']);
    }

    public function test_csv_neutralizes_formulas(): void
    {
        $this->event('s1', '10:00:00', ['utm_campaign' => '=HYPERLINK("http://x")', 'utm_source' => 'a', 'utm_medium' => 'b']);
        $this->event('s1', '10:01:00', [], 2);
        app(Aggregator::class)->run();

        $csv = $this->actingAs($this->owner)->get('/sites/'.$this->site->id.'/goals/'.$this->goal.'/export?from=2026-10-24&to=2026-10-26')->assertOk()->streamedContent();

        $rows = array_map(fn ($line) => str_getcsv($line, escape: ''), explode("\n", trim($csv)));
        $this->assertSame(['dimension', 'value', 'conversions', 'sessions', 'rate_percent'], $rows[0]);
        $this->assertSame(['total', 'all', '1', '1', '100'], $rows[1]);
        $campaign = collect($rows)->firstWhere(0, 'campaign');
        $this->assertStringStartsWith("'=HYPERLINK", $campaign[1]);
        $this->assertSame(['1', '1', '100'], array_slice($campaign, 2));
        $this->assertStringNotContainsString('s1', $csv);
    }

    public function test_goal_reports_are_private_to_site_members(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]))->getJson('/api/sites/'.$this->site->id.'/goals/'.$this->goal)->assertForbidden();
        $other = Website::factory()->create();
        $other->members()->attach($this->owner, ['role' => 'owner']);
        $this->actingAs($this->owner)->getJson('/api/sites/'.$other->id.'/goals/'.$this->goal)->assertNotFound();
        $this->get('/sites/'.$other->id.'/goals/'.$this->goal.'/export')->assertNotFound();
    }

    public function test_a_goal_defined_after_raw_events_were_deleted_is_marked_not_reconstructible(): void
    {
        DB::table('goal_versions')->where('goal_id', $this->goal)->update(['created_at' => now('UTC')->subDay()]);
        $this->travelTo(now('UTC')->addDays(120));

        $report = $this->actingAs($this->owner)->getJson('/api/sites/'.$this->site->id.'/goals/'.$this->goal.'?from=2026-10-24&to=2026-10-26')->json();

        $this->assertTrue($report['coverage']['not_reconstructible']);
        $this->assertSame('partial', $report['coverage']['status']);
    }
}
