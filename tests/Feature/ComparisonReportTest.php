<?php

namespace Tests\Feature;

use App\Analytics\Reporting\Reports;
use App\Installation\InstallationState;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ComparisonReportTest extends TestCase
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

    private function day(Website $site, string $date, int $pageviews): void
    {
        DB::table('stats_daily')->insert(['website_id' => $site->id, 'stat_date' => $date, 'pageviews' => $pageviews]);
    }

    /**
     * @return array<string, mixed>
     */
    private function previous(Website $site, array $query): array
    {
        $reports = app(Reports::class);

        return $reports->previousPeriod($reports->period(Request::create('/', 'GET', $query), $site), $site);
    }

    public function test_previous_period_has_the_same_length_and_ends_the_day_before(): void
    {
        $site = $this->memberSite();

        $week = $this->previous($site, ['from' => '2026-10-18', 'to' => '2026-10-24']);
        $this->assertSame(['2026-10-11', '2026-10-17'], [$week['from'], $week['to']]);
        $this->assertSame('day', $week['granularity']);

        $month = $this->previous($site, ['from' => '2026-10-01', 'to' => '2026-10-31']);
        $this->assertSame(['2026-08-31', '2026-09-30'], [$month['from'], $month['to']]);
    }

    public function test_a_single_day_is_compared_with_the_previous_day_by_hour(): void
    {
        $site = $this->memberSite();

        $day = $this->previous($site, ['from' => '2026-10-24', 'to' => '2026-10-24']);
        $this->assertSame(['2026-10-23', '2026-10-23'], [$day['from'], $day['to']]);
        $this->assertSame('hour', $day['granularity']);
        $this->assertSame('2026-10-23 00:00:00', $day['start']->toDateTimeString());
        $this->assertSame('2026-10-24 00:00:00', $day['end']->toDateTimeString());
    }

    public function test_previous_period_keeps_the_active_filters(): void
    {
        $site = $this->memberSite();

        $previous = $this->previous($site, ['from' => '2026-10-18', 'to' => '2026-10-24', 'source_group' => 'ai', 'provider' => 'claude']);
        $this->assertSame('ai', $previous['source_group']);
        $this->assertSame('claude', $previous['provider']);
    }

    public function test_comparison_is_only_returned_when_requested(): void
    {
        $site = $this->memberSite();
        $this->day($site, '2026-10-20', 10);
        $this->day($site, '2026-10-13', 4);
        $range = '?from=2026-10-18&to=2026-10-24';

        $this->get('/sites/'.$site->id.$range)->assertOk()->assertInertia(fn (Assert $page) => $page->missing('comparison'));
        $this->get('/sites/'.$site->id.$range.'&compare=previous')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('comparison.period.from', '2026-10-11')
            ->where('comparison.period.to', '2026-10-17')
            ->where('comparison.totals.pageviews', 4)
            ->where('report.totals.pageviews', 10)
            ->has('comparison.series', 7));
    }

    public function test_unknown_comparison_modes_are_rejected(): void
    {
        $site = $this->memberSite();

        $this->get('/sites/'.$site->id.'?from=2026-10-18&to=2026-10-24&compare=year')->assertSessionHasErrors('compare');
    }

    public function test_percent_is_withheld_when_range_includes_today(): void
    {
        $site = $this->memberSite();
        $this->day($site, '2026-10-20', 10);
        $this->day($site, '2026-10-13', 4);

        $this->get('/sites/'.$site->id.'?from=2026-10-18&to=2026-10-24&compare=previous')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('comparison.reliability.pageviews.percent_allowed', true)
            ->where('comparison.reliability.pageviews.reason', null));

        $today = now('UTC')->toDateString();
        $this->get('/sites/'.$site->id.'?from='.now('UTC')->subDays(6)->toDateString().'&to='.$today.'&compare=previous')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('comparison.reliability.pageviews.percent_allowed', false)
            ->where('comparison.reliability.pageviews.reason', 'current_period')
            ->where('comparison.reliability.custom_events.reason', 'current_period'));
    }

    public function test_a_zero_previous_value_withholds_the_percent(): void
    {
        $site = $this->memberSite();
        $this->day($site, '2026-10-20', 10);

        $this->get('/sites/'.$site->id.'?from=2026-10-18&to=2026-10-24&compare=previous')->assertInertia(fn (Assert $page) => $page
            ->where('comparison.reliability.pageviews.reason', 'previous_zero')
            ->where('comparison.totals.pageviews', 0));
    }

    private function measured(Website $site, string $since, string $mode = 'sessions', ?string $until = null): void
    {
        DB::table('site_measurement_periods')->insert(['website_id' => $site->id, 'started_at' => $since, 'ended_at' => $until, 'mode' => $mode]);
    }

    public function test_unavailable_previous_sessions_are_not_zero(): void
    {
        $site = $this->memberSite();
        $this->measured($site, '2026-10-18 00:00:00');
        DB::table('stats_daily')->insert(['website_id' => $site->id, 'stat_date' => '2026-10-20', 'pageviews' => 10, 'session_pageviews' => 10, 'sessions' => 5]);
        DB::table('stats_daily')->insert(['website_id' => $site->id, 'stat_date' => '2026-10-13', 'pageviews' => 4]);

        $this->get('/sites/'.$site->id.'?from=2026-10-18&to=2026-10-24&compare=previous')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('report.totals.sessions', 5)
            ->where('comparison.totals.sessions', null)
            ->where('comparison.availability.sessions', 'unavailable')
            ->where('comparison.reliability.sessions.percent_allowed', false)
            ->where('comparison.reliability.sessions.reason', 'unavailable')
            ->where('comparison.reliability.pageviews.reason', null));
    }

    public function test_unavailable_current_sessions_withhold_the_percent_too(): void
    {
        $site = $this->memberSite();
        $this->measured($site, '2026-10-01 00:00:00', 'sessions', '2026-10-15 00:00:00');
        DB::table('stats_daily')->insert(['website_id' => $site->id, 'stat_date' => '2026-10-13', 'pageviews' => 4, 'session_pageviews' => 4, 'sessions' => 2]);

        $this->get('/sites/'.$site->id.'?from=2026-10-18&to=2026-10-24&compare=previous')->assertInertia(fn (Assert $page) => $page
            ->where('report.totals.sessions', null)
            ->where('comparison.reliability.sessions.reason', 'unavailable'));
    }

    public function test_partial_coverage_and_pending_rebuild_withhold_percent(): void
    {
        $site = $this->memberSite();
        $this->measured($site, '2026-10-01 00:00:00', 'pageviews', '2026-10-20 00:00:00');
        $this->measured($site, '2026-10-20 00:00:00');
        foreach (['2026-10-13', '2026-10-20'] as $date) {
            DB::table('stats_daily')->insert(['website_id' => $site->id, 'stat_date' => $date, 'pageviews' => 6, 'session_pageviews' => 6, 'sessions' => 3, 'custom_events' => 2]);
        }
        $url = '/sites/'.$site->id.'?from=2026-10-18&to=2026-10-24&compare=previous';

        $this->get($url)->assertInertia(fn (Assert $page) => $page
            ->where('report.availability.sessions', 'partial')
            ->where('comparison.reliability.sessions.reason', 'partial_coverage')
            ->where('comparison.reliability.sessions.percent_allowed', false)
            ->where('comparison.reliability.pageviews.reason', null));

        DB::table('events')->insert(['website_id' => $site->id, 'received_at' => '2026-10-24 10:00:00', 'event_type' => 1, 'path' => '/', 'consent_given' => true]);
        $this->get($url)->assertInertia(fn (Assert $page) => $page
            ->where('report.freshness.pending', true)
            ->where('comparison.reliability.pageviews.reason', 'rebuilding')
            ->where('comparison.reliability.sessions.reason', 'rebuilding')
            ->where('comparison.reliability.custom_events.reason', 'rebuilding'));
    }

    public function test_sessions_missing_from_the_cross_filter_tables_withhold_the_percent(): void
    {
        $site = $this->memberSite();
        $this->measured($site, '2026-10-01 00:00:00');
        DB::table('stats_daily')->insert(['website_id' => $site->id, 'stat_date' => '2026-10-20', 'pageviews' => 6, 'session_pageviews' => 6, 'sessions' => 3]);
        DB::table('measured_sessions')->insert(['website_id' => $site->id, 'session_key' => str_repeat('a', 64), 'first_seen_at' => '2026-10-20 10:00:00', 'last_seen_at' => '2026-10-20 10:05:00']);

        $this->get('/sites/'.$site->id.'?from=2026-10-18&to=2026-10-24&compare=previous&filters[device][]=mobile')->assertInertia(fn (Assert $page) => $page
            ->where('report.segment_coverage.missing_sessions', 1)
            ->where('comparison.segment_coverage.missing_sessions', 0)
            ->where('comparison.reliability.sessions.reason', 'partial_coverage'));
    }

    private function comparisonFor(Website $site, array $query): array
    {
        $reports = app(Reports::class);

        return $reports->comparison($site, $reports->period(Request::create('/', 'GET', $query), $site));
    }

    public function test_previous_period_across_dst_and_leap_year(): void
    {
        $site = $this->memberSite();

        $year = $this->comparisonFor($site, ['from' => '2026-01-01', 'to' => '2026-01-07']);
        $this->assertSame(['2025-12-25', '2025-12-31'], [$year['period']['from'], $year['period']['to']]);
        $this->assertSame(range(0, 6), array_column($year['series'], 'rank'));
        $this->assertSame('2025-12-25', $year['series'][0]['date']);

        $leap = $this->comparisonFor($site, ['from' => '2024-03-01', 'to' => '2024-03-07']);
        $this->assertSame(['2024-02-23', '2024-02-29'], [$leap['period']['from'], $leap['period']['to']]);
        $this->assertCount(7, $leap['series']);

        $site->forceFill(['timezone' => 'Europe/Paris'])->save();
        $autumn = $this->comparisonFor($site, ['from' => '2026-10-25', 'to' => '2026-10-25']);
        $this->assertSame(['2026-10-24', '2026-10-24'], [$autumn['period']['from'], $autumn['period']['to']]);
        $byRank = array_column($autumn['series'], 'label', 'rank');
        $this->assertCount(24, $autumn['series']);
        $this->assertSame('02:00 CEST', $byRank[2]);
        $this->assertArrayNotHasKey(3, $byRank, 'the repeated 02:00 of the 25-hour day has no counterpart');
        $this->assertSame('03:00 CEST', $byRank[4]);
        $this->assertSame('23:00 CEST', $byRank[24]);

        $spring = $this->comparisonFor($site, ['from' => '2026-03-29', 'to' => '2026-03-29']);
        $byRank = array_column(array_filter($spring['series'], fn (array $point): bool => $point['rank'] !== null), 'label', 'rank');
        $this->assertSame('03:00 CET', $byRank[2], 'slot 2 of the 23-hour day is 03:00');
        $this->assertSame('23:00 CET', $byRank[22]);
        $this->assertCount(23, $byRank);

        $day = $this->comparisonFor($site, ['from' => '2026-06-10', 'to' => '2026-06-10']);
        $this->assertSame(['2026-06-09', '2026-06-09'], [$day['period']['from'], $day['period']['to']]);
        $this->assertCount(24, $day['series']);
    }

    public function test_purged_previous_history_is_unavailable_not_zero(): void
    {
        $site = $this->memberSite();
        $this->measured($site, '2026-10-18 00:00:00');
        DB::table('stats_daily')->insert(['website_id' => $site->id, 'stat_date' => '2026-10-20', 'pageviews' => 3, 'session_pageviews' => 3, 'sessions' => 2]);

        $comparison = $this->comparisonFor($site, ['from' => '2026-10-18', 'to' => '2026-10-24']);
        $this->assertNull($comparison['totals']['sessions']);
        $this->assertNull($comparison['series'][0]['sessions']);
        $this->assertSame('unavailable', $comparison['series'][0]['session_availability']);
    }

    /**
     * @return list<array{sql: string, bindings: array<int, mixed>}>
     */
    private function queriesDuring(callable $work): array
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
        });
        $work();

        return $queries;
    }

    public function test_comparison_adds_at_most_four_queries(): void
    {
        $site = $this->memberSite();
        $this->measured($site, '2026-10-01 00:00:00');
        $this->day($site, '2026-10-20', 10);
        $this->day($site, '2026-10-13', 4);
        $url = '/sites/'.$site->id.'?from=2026-10-18&to=2026-10-24';
        $this->get($url)->assertOk();

        $off = count($this->queriesDuring(fn () => $this->get($url)->assertOk()));
        $on = count($this->queriesDuring(fn () => $this->get($url.'&compare=previous')->assertOk()));
        $this->assertLessThanOrEqual(4, $on - $off, 'Comparing added '.($on - $off).' queries.');

        $hourlyOff = count($this->queriesDuring(fn () => $this->get('/sites/'.$site->id.'?from=2026-10-20&to=2026-10-20')->assertOk()));
        $hourlyOn = count($this->queriesDuring(fn () => $this->get('/sites/'.$site->id.'?from=2026-10-20&to=2026-10-20&compare=previous')->assertOk()));
        $this->assertLessThanOrEqual(4, $hourlyOn - $hourlyOff, 'Comparing one day added '.($hourlyOn - $hourlyOff).' queries.');
    }

    public function test_no_extra_query_when_off(): void
    {
        $site = $this->memberSite();
        $this->partialMock(Reports::class, function ($mock): void {
            $mock->shouldNotReceive('comparison');
            $mock->shouldNotReceive('previousPeriod');
        });

        $queries = $this->queriesDuring(fn () => $this->get('/sites/'.$site->id.'?from=2026-10-18&to=2026-10-24')->assertOk());

        foreach ($queries as $query) {
            $this->assertNotContains('2026-10-11', $query['bindings'], 'A query read the previous period: '.$query['sql']);
        }
    }

    public function test_api_overview_includes_comparison_only_when_requested(): void
    {
        $site = $this->memberSite();
        $this->day($site, '2026-10-20', 10);
        $this->day($site, '2026-10-13', 4);
        $range = '?from=2026-10-18&to=2026-10-24';
        $reports = app(Reports::class);
        $expected = $reports->overview($site, $reports->period(Request::create('/', 'GET', ['from' => '2026-10-18', 'to' => '2026-10-24']), $site));

        $off = $this->getJson('/api/sites/'.$site->id.'/overview'.$range)->assertOk();
        $this->assertSame(json_encode($expected), $off->baseResponse->getContent());
        $off->assertJsonMissingPath('comparison');

        $this->getJson('/api/sites/'.$site->id.'/overview'.$range.'&compare=previous')->assertOk()
            ->assertJsonPath('totals.pageviews', 10)
            ->assertJsonPath('comparison.period.from', '2026-10-11')
            ->assertJsonPath('comparison.totals.pageviews', 4)
            ->assertJsonPath('comparison.reliability.pageviews.percent_allowed', true);
        $this->getJson('/api/sites/'.$site->id.'/timeseries'.$range.'&compare=previous')->assertOk()->assertJsonMissingPath('comparison');
        $this->getJson('/api/sites/'.$site->id.'/overview'.$range.'&compare=year')->assertStatus(422);
    }

    public function test_csv_export_ignores_compare(): void
    {
        $site = $this->memberSite();
        $this->day($site, '2026-10-20', 10);
        $range = '?from=2026-10-18&to=2026-10-24';

        $plain = $this->get('/sites/'.$site->id.'/export'.$range)->assertOk()->streamedContent();
        $compared = $this->get('/sites/'.$site->id.'/export'.$range.'&compare=previous')->assertOk()->streamedContent();
        $this->assertSame($plain, $compared);
    }
}
