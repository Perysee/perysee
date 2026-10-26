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
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ReportConsistencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
    }

    private function memberSite(): Website
    {
        $user = User::factory()->create();
        $site = Website::factory()->create(['timezone' => 'UTC']);
        $site->members()->attach($user, ['role' => 'owner']);
        $this->actingAs($user);

        return $site;
    }

    public function test_filter_changes_the_dimension_total_chart_and_export_together(): void
    {
        $site = $this->memberSite();
        foreach (['search.example' => 6, 'newsletter.example' => 4] as $value => $count) {
            DB::table('stats_dimension_daily')->insert(['website_id' => $site->id, 'stat_date' => '2026-10-24', 'dimension_type' => 'source', 'dimension_hash' => hash('sha256', $value), 'dimension_value' => $value, 'metric_count' => $count]);
        }
        $reports = app(Reports::class);
        $period = $reports->period(Request::create('/', 'GET', ['from' => '2026-10-24', 'to' => '2026-10-24']), $site);
        $filtered = $reports->dimensionReport($site, $period, 'source', 'newsletter.example');
        $this->assertSame(4, $filtered['total']);
        $this->assertSame(1, $filtered['distinct_values']);
        $this->assertSame(4, array_sum(array_column($filtered['series'], 'pageviews')));
        $query = '?from=2026-10-24&to=2026-10-24&value=newsletter.example';
        $this->get('/sites/'.$site->id.'/sources'.$query)->assertOk()->assertInertia(fn (Assert $page) => $page->where('section', 'sources')->where('dimensionReport.total', 4)->has('dimensionReport.data', 1)->where('dimensionReport.data.0.value', 'newsletter.example')->where('dimensionReport.series.0.pageviews', 4));
        $csv = $this->get('/sites/'.$site->id.'/export'.$query.'&dimension=source')->assertOk()->streamedContent();
        $this->assertStringContainsString('newsletter.example,4', $csv);
        $this->assertStringNotContainsString('search.example', $csv);
    }

    public function test_reception_counts_the_entire_window_even_when_the_list_is_capped(): void
    {
        $site = $this->memberSite();
        for ($index = 0; $index < 105; $index++) {
            DB::table('events')->insert(['website_id' => $site->id, 'received_at' => now('UTC'), 'path' => '/', 'consent_given' => true, 'event_type' => $index < 103 ? 1 : 2, 'event_name' => $index < 103 ? null : 'signup']);
        }
        $response = $this->getJson('/api/sites/'.$site->id.'/recent')->assertOk();
        $response->assertJsonCount(100, 'data')->assertJsonPath('totals.events', 105)->assertJsonPath('totals.pageviews', 103)->assertJsonPath('totals.custom_events', 2);
        $this->get('/sites/'.$site->id.'/realtime')->assertOk()->assertInertia(fn (Assert $page) => $page->where('section', 'realtime')->where('receptionTotals.events', 105)->has('recent', 100)->has('series', 0));
    }

    public function test_new_collection_populates_all_supported_reports_without_double_counting(): void
    {
        $site = $this->memberSite();
        $site->update(['tracking_mode' => 'sessions']);
        $payload = ['v' => 1, 'site' => $site->public_id, 'type' => 'pageview', 'mode' => 'sessions', 'host' => $site->domain, 'path' => '/pricing', 'consent' => true, 'session' => str_repeat('f', 32), 'ref' => 'https://search.example/result?private=discard', 'utm_source' => 'newsletter', 'utm_medium' => 'email', 'utm_campaign' => 'launch'];
        $this->withHeaders(['Origin' => 'https://'.$site->domain, 'User-Agent' => 'Mobile iPhone'])->postJson('/api/collect', $payload)->assertNoContent();
        $this->postJson('/api/collect', array_merge($payload, ['path' => '/thanks']))->assertNoContent();
        $this->postJson('/api/collect', array_merge($payload, ['type' => 'event', 'event_name' => 'signup']))->assertNoContent();
        $this->assertSame(3, app(Aggregator::class)->run(1));
        $this->assertSame(0, app(Aggregator::class)->run(1));
        $query = '?period=today';
        $this->getJson('/api/sites/'.$site->id.'/overview'.$query)->assertJsonPath('totals.pageviews', 2)->assertJsonPath('totals.sessions', 1)->assertJsonPath('totals.custom_events', 1);
        foreach (['source' => 2, 'device' => 2, 'campaign' => 2, 'event' => 1, 'path' => 2] as $dimension => $expected) {
            $rows = $this->getJson('/api/sites/'.$site->id.'/dimensions'.$query.'&dimension='.$dimension)->assertOk()->json('data');
            $this->assertSame($expected, array_sum(array_column($rows, 'count')));
        }
        foreach (['sources', 'campaigns', 'devices', 'events', 'sessions', 'pages'] as $section) {
            $this->get('/sites/'.$site->id.'/'.$section.$query)->assertOk();
        }
    }

    public function test_report_navigation_is_single_and_preserves_the_selected_period(): void
    {
        $site = $this->memberSite();
        $response = $this->get('/sites/'.$site->id.'?from=2026-10-24&to=2026-10-24')->assertOk();
        $response->assertInertia(fn (Assert $page) => $page->where('period.from', '2026-10-24')->where('period.to', '2026-10-24')->where('siteLinks.campaigns', url('/sites/'.$site->id.'/campaigns'))->missing('section'));
        $this->get('/sites/'.$site->id.'/sessions?period=today')->assertOk()->assertInertia(fn (Assert $page) => $page->where('section', 'sessions')->has('dimensions', 0)->where('dimensionReport', null)->has('report.availability.sessions'));
    }
}
