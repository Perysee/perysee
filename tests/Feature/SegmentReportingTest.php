<?php

namespace Tests\Feature;

use App\Analytics\Aggregation\Aggregator;
use App\Analytics\Aggregation\Segments;
use App\Analytics\Reporting\Reports;
use App\Installation\InstallationState;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SegmentReportingTest extends TestCase
{
    use RefreshDatabase;

    private function event(Website $site, string $country, string $device, string $time, ?string $session = null): int
    {
        return DB::table('events')->insertGetId(['website_id' => $site->id, 'received_at' => $time, 'path' => '/pricing', 'country_code' => $country, 'geo_available' => true, 'device_type' => $device, 'source_value' => 'chatgpt', 'dimensions_available' => true, 'tracking_mode' => 'sessions', 'session_key' => $session, 'consent_given' => true]);
    }

    public function test_crossed_filters_use_joint_observations_and_share_the_same_series_and_dimensions(): void
    {
        $site = Website::factory()->create(['tracking_mode' => 'sessions']);
        $this->event($site, 'FR', 'mobile', '2026-10-24 10:00:00', 'a');
        $this->event($site, 'FR', 'desktop', '2026-10-24 11:00:00', 'b');
        $this->event($site, 'US', 'mobile', '2026-10-24 12:00:00', 'c');
        app(Aggregator::class)->run();
        $reports = app(Reports::class);
        $period = $reports->period(Request::create('/', 'GET', ['from' => '2026-10-24', 'to' => '2026-10-24', 'filters' => ['country' => ['FR'], 'device' => ['mobile', 'tablet']]]), $site);
        $this->assertSame(1, $reports->overview($site, $period)['totals']['pageviews']);
        $this->assertSame(1, array_sum(array_column($reports->series($site, $period), 'pageviews')));
        $this->assertSame(1, $reports->dimensionReport($site, $period, 'path')['total']);
    }

    public function test_reconstruction_is_idempotent_and_a_late_first_page_moves_session_attribution(): void
    {
        $site = Website::factory()->create(['tracking_mode' => 'sessions']);
        $this->event($site, 'US', 'desktop', '2026-10-24 12:00:00', 'same');
        app(Aggregator::class)->run();
        $this->event($site, 'FR', 'mobile', '2026-10-24 10:00:00', 'same');
        app(Aggregator::class)->run();
        app(Segments::class)->rebuild(100);
        app(Segments::class)->rebuild(100);
        $reports = app(Reports::class);
        foreach (['FR' => 1, 'US' => 0] as $country => $sessions) {
            $period = $reports->period(Request::create('/', 'GET', ['from' => '2026-10-24', 'to' => '2026-10-24', 'filters' => ['country' => [$country]]]), $site);
            $this->assertSame($sessions, $reports->overview($site, $period)['totals']['sessions']);
            $this->assertSame(1, $reports->overview($site, $period)['totals']['pageviews']);
        }
    }

    public function test_retained_processed_events_are_rebuilt_once_in_small_batches(): void
    {
        $site = Website::factory()->create();
        $ids = [$this->event($site, 'FR', 'mobile', '2026-10-24 10:00:00'), $this->event($site, 'FR', 'mobile', '2026-10-24 11:00:00')];
        DB::table('events')->whereIn('id', $ids)->update(['processed_at' => now()]);
        $this->assertSame(1, app(Segments::class)->rebuild(1));
        $this->assertSame(1, app(Segments::class)->rebuild(1));
        $this->assertSame(0, app(Segments::class)->rebuild(1));
        $this->assertSame(2, (int) DB::table('stats_segment_daily')->sum('pageviews'));
        $this->assertDatabaseCount('segment_events', 2);
    }

    public function test_cross_filter_api_export_and_options_keep_site_permissions(): void
    {
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
        $site = Website::factory()->create();
        $user = User::factory()->create();
        $site->members()->attach($user, ['role' => 'viewer']);
        $this->event($site, 'FR', 'mobile', '2026-10-24 10:00:00');
        $this->event($site, 'US', 'mobile', '2026-10-24 10:00:00');
        app(Aggregator::class)->run();
        $query = http_build_query(['from' => '2026-10-24', 'to' => '2026-10-24', 'filters' => ['country' => ['FR'], 'device' => ['mobile']]]);
        $this->actingAs($user)->get('/api/sites/'.$site->id.'/overview?'.$query)->assertOk()->assertJsonPath('totals.pageviews', 1);
        $this->get('/sites/'.$site->id.'/export?'.$query.'&dimension=country')->assertOk()->assertStreamedContent("value,count\nFR,1\n");
        $this->get('/api/sites/'.$site->id.'/filter-options?'.$query.'&filter_dimension=device')->assertOk()->assertJsonPath('data.0', 'mobile');
        $this->getJson('/api/sites/'.$site->id.'/overview?filters[forged][]=x')->assertUnprocessable();
        $this->actingAs(User::factory()->create(['is_admin' => true]))->get('/api/sites/'.$site->id.'/filter-options?filter_dimension=country')->assertForbidden();
    }

    public function test_missing_first_page_context_is_not_reported_as_zero_filtered_sessions(): void
    {
        $site = Website::factory()->create(['tracking_mode' => 'sessions']);
        DB::table('measured_sessions')->insert(['website_id' => $site->id, 'session_key' => 'missing', 'first_seen_at' => '2026-10-24 09:00:00', 'last_seen_at' => '2026-10-24 11:00:00']);
        $this->event($site, 'FR', 'mobile', '2026-10-24 11:00:00', 'missing');
        app(Aggregator::class)->run();
        $reports = app(Reports::class);
        $period = $reports->period(Request::create('/', 'GET', ['from' => '2026-10-24', 'to' => '2026-10-24', 'filters' => ['country' => ['FR']]]), $site);
        $this->assertNull($reports->overview($site, $period)['totals']['sessions']);
        foreach ($reports->series($site, $period) as $row) {
            $this->assertNull($row['sessions']);
        }
    }
}
