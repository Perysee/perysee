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

    /**
     * The overview as the read API returns it: what a share link shows, plus whether data is still being processed,
     * the history of measurement modes, and the coverage of cross-filtered statistics when filters apply.
     *
     * @param  array<string, mixed>  $report  a result of Reports::overview()
     */
    public function apiOverviewOf(array $report): array
    {
        $data = $this->overviewOf($report);
        $data['freshness']['pending'] = (bool) ($report['freshness']['pending'] ?? false);
        $data['measurement_periods'] = array_map(fn (array $entry): array => ['started_at' => $entry['started_at'], 'ended_at' => $entry['ended_at'], 'mode' => $entry['mode']], $report['measurement_periods'] ?? []);
        if (isset($report['segment_coverage'])) {
            $data['segment_coverage'] = array_intersect_key($report['segment_coverage'], array_flip(['status', 'missing_sessions', 'unmeasured_dimensions']));
        }

        return $data;
    }

    /**
     * The comparison block with the server verdict on percentages. The client never computes one itself.
     *
     * @param  array<string, mixed>  $comparison  a result of Reports::comparison()
     */
    public function apiComparisonOf(array $comparison): array
    {
        return ['period' => $comparison['period'], 'totals' => $comparison['totals'], 'coverage' => $comparison['coverage'] ?? null, 'availability' => $comparison['availability'] ?? null, 'reliability' => $comparison['reliability']];
    }

    /**
     * @param  list<array<string, mixed>>  $series
     * @return list<array<string, mixed>>
     */
    public function apiPoints(array $series): array
    {
        return array_map(fn (array $point): array => [
            'date' => $point['date'], 'label' => $point['label'], 'pageviews' => $point['pageviews'], 'custom_events' => $point['custom_events'],
            'sessions' => $point['sessions'], 'session_availability' => $point['session_availability'] ?? null,
        ], $series);
    }
}
