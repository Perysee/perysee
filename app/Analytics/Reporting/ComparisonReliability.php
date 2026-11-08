<?php

namespace App\Analytics\Reporting;

use App\Analytics\Continuity\ContinuityLog;
use App\Models\Website;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ComparisonReliability
{
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
        $report = app(SeriesReport::class);
        $current ??= $report->overview($site, $period);
        $previous = app(PeriodResolver::class)->previousPeriod($period, $site);
        if ($totalsOnly && ($current['freshness']['pending'] ?? false)) {
            $reliability = array_fill_keys(['pageviews', 'sessions', 'custom_events'], ['percent_allowed' => false, 'reason' => 'rebuilding']);

            return ['period' => ['from' => $previous['from'], 'to' => $previous['to']], 'totals' => null, 'reliability' => $reliability];
        }
        $known = [
            'rows' => DB::table('stats_daily')->where('website_id', $site->id)->whereBetween('stat_date', [$previous['from'], $previous['to']])->get(),
            'history' => $report->measurementPeriods($site, $previous),
            'freshness' => $current['freshness'],
        ];
        $overview = $report->overview($site, $previous, $known);
        $changes = array_values(array_filter(app(ContinuityLog::class)->between($site, $previous['from'], $period['to']), fn (array $entry): bool => $entry['severity'] === ContinuityLog::BREAKS));

        return [
            'period' => ['from' => $previous['from'], 'to' => $previous['to']],
            'totals' => $overview['totals'],
            'series' => $totalsOnly ? [] : $this->alignSeries($site, $previous, $period, $report->series($site, $previous, $known)),
            'availability' => $overview['availability'],
            'coverage' => $overview['coverage'],
            'freshness' => $overview['freshness'],
            ...(isset($overview['segment_coverage']) ? ['segment_coverage' => $overview['segment_coverage']] : []),
            'reliability' => $this->reliability($site, $period, $current, $overview, $changes),
        ];
    }

    /**
     * The reasons are tried in order of priority: a new reason is a new arm of the match below, with the fact it needs read above it.
     *
     * @param  list<array{at: string, kind: string, scope: string, severity: string}>  $changes  the breaks of the continuity log from the start of the previous period to the end of this one (one read)
     * @return array<string, array{percent_allowed: bool, reason: string|null}>
     */
    private function reliability(Website $site, array $period, array $current, array $previous, array $changes): array
    {
        $includesToday = $period['to'] >= now($site->timezone)->toDateString();
        $sides = [$current, $previous];
        $rebuilding = ($current['freshness']['pending'] ?? false) || collect($sides)->contains(fn (array $side): bool => ($side['segment_coverage']['status'] ?? null) === 'rebuilding');
        $sessionsGone = collect($sides)->contains(fn (array $side): bool => $side['availability']['sessions'] === 'unavailable');
        $sessionsPartial = collect($sides)->contains(fn (array $side): bool => $side['availability']['sessions'] === 'partial' || ($side['segment_coverage']['missing_sessions'] ?? 0) > 0);
        $verdict = [];
        $scopes = ['pageviews' => ['all', 'pageviews'], 'sessions' => ['all', 'sessions'], 'custom_events' => ['all', 'events']];
        foreach (['pageviews', 'sessions', 'custom_events'] as $metric) {
            $sessions = $metric === 'sessions';
            $reason = match (true) {
                $metric !== 'custom_events' && ! empty($period['filters']['event']) => 'not_applicable',
                $rebuilding => 'rebuilding',
                $sessions && $sessionsGone => 'unavailable',
                $sessions && $sessionsPartial => 'partial_coverage',
                $sessions && ($current['totals'][$metric] === null || $previous['totals'][$metric] === null) => 'unavailable',
                collect($changes)->contains(fn (array $entry): bool => in_array($entry['scope'], $scopes[$metric], true)) => 'config_change',
                $includesToday => 'current_period',
                ($previous['totals'][$metric] ?? 0) === 0 => 'previous_zero',
                default => null,
            };
            $verdict[$metric] = ['percent_allowed' => $reason === null, 'reason' => $reason];
        }

        return $verdict;
    }

    /**
     * Number the points of the previous series by the slot of the current period they line up with: the day rank, or
     * the local hour of the day (so 23 and 25 hour days leave the missing slot empty instead of shifting the rest).
     *
     * @param  list<array<string, mixed>>  $series
     * @return list<array<string, mixed>>
     */
    private function alignSeries(Website $site, array $period, array $current, array $series): array
    {
        $slots = [];
        if ($period['granularity'] === 'hour') {
            foreach (array_values(app(SeriesReport::class)->bucketKeys($site, $current)) as $rank => $label) {
                $slots[substr($label, 0, 5)] ??= $rank;
            }
        }
        foreach ($series as $index => &$point) {
            $point['rank'] = $period['granularity'] === 'hour' ? ($slots[substr($point['label'], 0, 5)] ?? null) : $index;
            $point['day'] = $period['granularity'] === 'hour' ? Carbon::parse($point['date'], 'UTC')->setTimezone($site->timezone)->toDateString() : $point['date'];
        }
        if ($period['granularity'] === 'hour') {
            $seen = [];
            foreach ($series as &$point) {
                $point['rank'] = isset($seen[$point['rank']]) ? null : $point['rank'];
                $seen[$point['rank']] = true;
            }
        }

        return $series;
    }
}
