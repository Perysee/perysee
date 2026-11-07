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
}
