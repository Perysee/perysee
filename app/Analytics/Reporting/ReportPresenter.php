<?php

namespace App\Analytics\Reporting;

use App\Models\Website;

/**
 * Allow-lists of what the outside world may read from a report. Public share links and the read API both go
 * through here, so a field added to an internal report is never exposed by accident.
 */
class ReportPresenter
{
    public function __construct(private Reports $reports) {}

    /** The totals and coverage a visitor needs, without instance-level details. */
    public function overview(Website $site, array $period): array
    {
        return $this->overviewOf($this->reports->overview($site, $period));
    }

    /** @param  array<string, mixed>  $report  a result of Reports::overview() */
    public function overviewOf(array $report): array
    {
        return [
            'period' => $report['period'],
            'totals' => $report['totals'],
            'coverage' => $report['coverage'],
            'availability' => $report['availability'],
            'freshness' => ['aggregated_at' => $report['freshness']['aggregated_at']],
        ];
    }

    public function dimensionReport(Website $site, array $period, string $dimension, ?string $value = null): array
    {
        $report = $this->reports->dimensionReport($site, $period, $dimension, $value);

        return ['total' => $report['total'], 'distinct_values' => $report['distinct_values'], 'series' => $report['series'], 'data' => $report['data'], 'map' => $report['map']];
    }

    public function series(Website $site, array $period): array
    {
        return $this->reports->series($site, $period);
    }
}
