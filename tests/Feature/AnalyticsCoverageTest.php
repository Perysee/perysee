<?php

namespace Tests\Feature;

use App\Analytics\Aggregation\Aggregator;
use App\Analytics\Reporting\Reports;
use App\Installation\InstallationState;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AnalyticsCoverageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
    }

    public function test_autumn_clock_change_keeps_two_distinct_local_two_oclock_buckets(): void
    {
        $site = Website::factory()->create(['timezone' => 'Europe/Paris', 'tracking_mode' => 'sessions']);
        foreach (['2026-10-25 00:30:00', '2026-10-25 01:30:00'] as $received) {
            DB::table('events')->insert(['website_id' => $site->id, 'received_at' => $received, 'path' => '/', 'session_key' => str_repeat('d', 64), 'consent_given' => true, 'tracking_mode' => 'sessions']);
        }
        app(Aggregator::class)->run(1);
        $reports = app(Reports::class);
        $period = $reports->period(Request::create('/', 'GET', ['from' => '2026-10-25', 'to' => '2026-10-25']), $site);
        $series = $reports->series($site, $period);
        $this->assertCount(25, $series);
        $this->assertSame(2, array_sum(array_column($series, 'pageviews')));
        $this->assertSame(1, array_sum(array_column($series, 'sessions')));
        $this->assertSame(2, DB::table('stats_hourly')->count());
    }

    public function test_exports_escape_formulas_and_respect_the_dimension_filter(): void
    {
        $site = Website::factory()->create();
        $viewer = User::factory()->create();
        $site->members()->attach($viewer, ['role' => 'viewer']);
        foreach (['=DANGEROUS()', 'newsletter'] as $source) {
            DB::table('stats_dimension_daily')->insert(['website_id' => $site->id, 'stat_date' => '2026-10-24', 'dimension_type' => 'source', 'dimension_hash' => hash('sha256', $source), 'dimension_value' => $source, 'metric_count' => 3]);
        }
        $query = '?from=2026-10-24&to=2026-10-24&dimension=source&value='.urlencode('=DANGEROUS()');
        $api = $this->actingAs($viewer)->getJson('/api/sites/'.$site->id.'/dimensions'.$query)->assertOk()->json('data');
        $this->assertCount(1, $api);
        $this->assertSame('=DANGEROUS()', $api[0]['value']);
        $this->assertSame(3, $api[0]['count']);
        $csv = $this->get('/sites/'.$site->id.'/export'.$query)->assertOk()->streamedContent();
        $this->assertStringContainsString("'=DANGEROUS()", $csv);
        $this->assertStringNotContainsString('newsletter', $csv);
    }

    public function test_the_dimension_export_neutralises_every_text_a_spreadsheet_could_run_and_leaves_numbers_alone(): void
    {
        $site = Website::factory()->create();
        $viewer = User::factory()->create();
        $site->members()->attach($viewer, ['role' => 'viewer']);
        $values = ['+33 promo', '-sale', "\tpad", "\x00=cmd", "=\xff", 'plain'];
        foreach ($values as $index => $source) {
            DB::table('stats_dimension_daily')->insert(['website_id' => $site->id, 'stat_date' => '2026-10-24', 'dimension_type' => 'source', 'dimension_hash' => hash('sha256', $source), 'dimension_value' => $source, 'metric_count' => 3 + $index]);
        }

        $csv = $this->actingAs($viewer)->get('/sites/'.$site->id.'/export?from=2026-10-24&to=2026-10-24&dimension=source')->assertOk()->streamedContent();

        $cells = [];
        foreach (array_slice(explode("\n", trim($csv)), 1) as $line) {
            $row = str_getcsv($line, escape: '');
            $cells[$row[0]] = $row[1];
        }
        foreach (array_slice($values, 0, 5) as $value) {
            $this->assertArrayHasKey("'".$value, $cells);
        }
        $this->assertSame('8', $cells['plain']);
        $this->assertSame('3', $cells["'+33 promo"]);
    }

    public function test_old_tracker_dimensions_are_not_invented_and_mixed_measurement_is_partial(): void
    {
        $site = Website::factory()->create(['tracking_mode' => 'sessions']);
        foreach ([null, 'pageviews', 'sessions'] as $mode) {
            DB::table('events')->insert(['website_id' => $site->id, 'received_at' => '2026-10-24 10:00:00', 'path' => '/', 'tracking_mode' => $mode, 'session_key' => $mode === 'sessions' ? str_repeat('e', 64) : null, 'consent_given' => true]);
        }
        app(Aggregator::class)->run();
        $reports = app(Reports::class);
        $period = $reports->period(Request::create('/', 'GET', ['from' => '2026-10-24', 'to' => '2026-10-24']), $site);
        $overview = $reports->overview($site, $period);
        $this->assertSame('partial', $overview['availability']['sessions']);
        $this->assertSame('partial', $overview['availability']['dimensions']);
        $this->assertSame([], $reports->dimensions($site, $period, 'source'));
        $this->assertSame(1, $overview['totals']['sessions']);
    }

    public function test_history_marks_periods_where_session_measurement_was_disabled(): void
    {
        $this->travelTo(now('UTC')->setDate(2026, 10, 25)->startOfDay());
        $owner = User::factory()->create();
        $site = Website::factory()->create(['tracking_mode' => 'sessions']);
        $site->members()->attach($owner, ['role' => 'owner']);
        DB::table('site_measurement_periods')->insert(['website_id' => $site->id, 'started_at' => '2026-10-23 00:00:00', 'ended_at' => '2026-10-24 12:00:00', 'mode' => 'pageviews']);
        DB::table('site_measurement_periods')->insert(['website_id' => $site->id, 'started_at' => '2026-10-24 12:00:00', 'ended_at' => null, 'mode' => 'sessions']);
        $report = $this->actingAs($owner)->getJson('/api/sites/'.$site->id.'/overview?from=2026-10-24&to=2026-10-24')->assertOk();
        $report->assertJsonPath('availability.sessions', 'partial');
        $report->assertJsonPath('measurement_periods.0.mode', 'pageviews');
        $this->getJson('/api/sites/'.$site->id.'/overview?from=2026-10-23&to=2026-10-23')->assertJsonPath('totals.sessions', null);
    }

    public function test_path_exclusions_and_obvious_personal_campaign_values_are_handled_before_storage(): void
    {
        $site = Website::factory()->create(['excluded_paths' => ['/admin/*']]);
        $payload = ['v' => 1, 'site' => $site->public_id, 'type' => 'pageview', 'mode' => 'pageviews', 'host' => $site->domain, 'path' => '/admin/settings', 'consent' => true];
        $this->withHeader('Origin', 'https://'.$site->domain)->postJson('/api/collect', $payload)->assertForbidden();
        $this->assertDatabaseCount('events', 0);
        $this->postJson('/api/collect', array_merge($payload, ['path' => '/public', 'ref' => 'https://user:secret@example.org/private', 'utm_source' => 'a@example.com']))->assertNoContent();
        $this->assertDatabaseHas('events', ['referrer_host' => null, 'utm_source' => ':redacted']);
    }
}
