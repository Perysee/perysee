<?php

namespace App\Analytics\Reporting;

use App\Analytics\Continuity\ContinuityLog;
use App\Models\Website;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The reports every screen and API reads. The work is done by PeriodResolver, SeriesReport, ComparisonReliability
 * and DimensionReports; this class keeps one entry point for the controllers, the presenters and the modules.
 */
class Reports
{
    public function period(Request $request, Website $site): array
    {
        return app(PeriodResolver::class)->period($request, $site);
    }

    /**
     * The period of the same length that ends the day before $period starts, with the same filters.
     *
     * @param  array<string, mixed>  $period
     * @return array<string, mixed>
     */
    public function previousPeriod(array $period, Website $site): array
    {
        return app(PeriodResolver::class)->previousPeriod($period, $site);
    }

    /**
     * The previous period with its totals, series and the server-side verdict on whether a percentage may be shown.
     *
     * @param  array<string, mixed>  $period
     * @param  array<string, mixed>|null  $current  the overview of $period when the caller already has it
     * @param  bool  $totalsOnly  skip the series, and skip a site whose statistics are being rebuilt altogether
     * @return array<string, mixed>
     */
    public function comparison(Website $site, array $period, ?array $current = null, bool $totalsOnly = false): array
    {
        return app(ComparisonReliability::class)->comparison($site, $period, $current, $totalsOnly);
    }

    /**
     * The entries of the continuity log on the days of $period (date, kind, scope, severity, never the payload).
     *
     * @param  array<string, mixed>  $period
     * @return list<array{at: string, kind: string, scope: string, severity: string}>
     */
    public function continuity(Website $site, array $period): array
    {
        return app(ContinuityLog::class)->between($site, $period['from'], $period['to']);
    }

    /**
     * @param  array{rows?: Collection, history?: array, freshness?: array}  $known  reads the caller already made
     */
    public function overview(Website $site, array $period, array $known = []): array
    {
        return app(SeriesReport::class)->overview($site, $period, $known);
    }

    /**
     * @param  array{rows?: Collection, history?: array}  $known  the daily rows and measurement periods the caller already read
     */
    public function series(Website $site, array $period, array $known = []): array
    {
        return app(SeriesReport::class)->series($site, $period, $known);
    }

    public function dimensions(Website $site, array $period, string $type = 'path', ?string $value = null): array
    {
        return app(DimensionReports::class)->dimensions($site, $period, $type, $value);
    }

    public function dimensionReport(Website $site, array $period, string $type, ?string $value = null): array
    {
        return app(DimensionReports::class)->dimensionReport($site, $period, $type, $value);
    }

    public function clientCoverage(Website $site, array $period): array
    {
        return app(DimensionReports::class)->clientCoverage($site, $period);
    }

    public function receptionTotals(Website $site): array
    {
        $row = DB::table('events')->where('website_id', $site->id)->where('received_at', '>=', now('UTC')->subMinutes(15))
            ->selectRaw('COUNT(*) AS events, SUM(CASE WHEN event_type = 1 THEN 1 ELSE 0 END) AS pageviews, SUM(CASE WHEN event_type = 2 THEN 1 ELSE 0 END) AS custom_events')->first();

        return ['events' => (int) $row->events, 'pageviews' => (int) $row->pageviews, 'custom_events' => (int) $row->custom_events];
    }

    public function measurementPeriods(Website $site, array $period): array
    {
        return app(SeriesReport::class)->measurementPeriods($site, $period);
    }

    public function recent(Website $site): array
    {
        return DB::table('events')->where('website_id', $site->id)->where('received_at', '>=', now('UTC')->subMinutes(15))->orderByDesc('received_at')->orderByDesc('id')->limit(100)->get(['received_at', 'event_type', 'event_name', 'path'])->map(fn ($event) => (array) $event)->all();
    }
}
