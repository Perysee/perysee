<?php

namespace App\Analytics\Reporting;

use App\Analytics\InstanceHealth;
use App\Models\Website;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The totals and the series of a period, and the measurement history that tells whether sessions were counted.
 */
class SeriesReport
{
    /**
     * @param  array{rows?: Collection, history?: array, freshness?: array}  $known  reads the caller already made
     */
    public function overview(Website $site, array $period, array $known = []): array
    {
        $rows = $known['rows'] ?? DB::table('stats_daily')->where('website_id', $site->id)->whereBetween('stat_date', [$period['from'], $period['to']])->get();
        $pageviews = (int) $rows->sum('pageviews');
        $measured = (int) $rows->sum('session_pageviews');
        $unknown = (int) $rows->sum('unknown_pageviews');
        $history = $known['history'] ?? $this->measurementPeriods($site, $period);
        $coverage = $this->sessionCoverage($period['start'], $period['end'], $history);
        $hasDisabled = collect($history)->contains(fn ($entry) => $entry['mode'] === 'pageviews');
        $available = $measured > 0 || $coverage !== 'unavailable';
        $sessions = (int) $rows->sum('sessions');
        $knownDimensions = (int) $rows->sum('dimension_pageviews');
        $sessionAvailability = ! $available && $sessions === 0 ? 'unavailable' : ($unknown > 0 || $coverage === 'partial' || ($hasDisabled && $available) || ($measured > 0 && $measured < $pageviews) ? 'partial' : 'available');

        $base = [
            'period' => ['from' => $period['from'], 'to' => $period['to'], 'timezone' => $site->timezone],
            'totals' => ['pageviews' => $pageviews, 'custom_events' => (int) $rows->sum('custom_events'), 'sessions' => $available || $sessions > 0 ? $sessions : null],
            'coverage' => ['measured_pageviews' => $measured, 'pageviews' => $pageviews, 'country_pageviews' => (int) $rows->sum('country_pageviews')],
            'attribution_v2_since' => isset($known['freshness']) ? null : app(InstanceHealth::class)->get('attribution_v2_since'),
            'measurement_periods' => $history,
            'availability' => ['sessions' => $sessionAvailability, 'dimensions' => $pageviews === 0 ? 'unavailable' : ($knownDimensions === $pageviews ? 'available' : 'partial'), 'countries' => $rows->sum('country_pageviews') === 0 ? 'unavailable' : ((int) $rows->sum('country_pageviews') < $pageviews ? 'partial' : 'available')],
            'freshness' => $known['freshness'] ?? ['aggregated_at' => app(InstanceHealth::class)->get('aggregate_site:'.$site->id), 'pending' => DB::table('events')->where('website_id', $site->id)->whereNull('processed_at')->exists()],
        ];

        return ! empty($period['filters']) ? app(SegmentReports::class)->overview($site, $period, $base) : $base;
    }

    /**
     * The buckets of a period keyed as in the stats tables: UTC hours (labelled in site time) or local dates.
     *
     * @return array<string, string>
     */
    public function bucketKeys(Website $site, array $period): array
    {
        $keys = [];
        if ($period['granularity'] === 'hour') {
            for ($cursor = $period['start']->copy(); $cursor->lt($period['end']); $cursor->addMinute()) {
                $hour = $cursor->copy()->setTimezone($site->timezone)->startOfHour()->utc()->toDateTimeString();
                $keys[$hour] = Carbon::parse($hour, 'UTC')->setTimezone($site->timezone)->format('H:i T');
            }
        } else {
            for ($day = Carbon::parse($period['from']); $day->toDateString() <= $period['to']; $day->addDay()) {
                $keys[$day->toDateString()] = $day->toDateString();
            }
        }

        return $keys;
    }

    /**
     * @param  array{rows?: Collection, history?: array}  $known  the daily rows and measurement periods the caller already read
     */
    public function series(Website $site, array $period, array $known = []): array
    {
        $hourly = $period['granularity'] === 'hour';
        $rows = ! $hourly && isset($known['rows']) ? $known['rows']->keyBy('stat_date') : DB::table($hourly ? 'stats_hourly' : 'stats_daily')->where('website_id', $site->id)->whereBetween('stat_date', [$period['from'], $period['to']])->orderBy($hourly ? 'bucket_start' : 'stat_date')->get()->keyBy($hourly ? 'bucket_start' : 'stat_date');
        $keys = $this->bucketKeys($site, $period);
        $history = $known['history'] ?? $this->measurementPeriods($site, $period);
        $series = [];
        foreach ($keys as $key => $label) {
            $row = $rows->get($key);
            $bucketStart = $hourly ? Carbon::parse($key, 'UTC') : Carbon::parse($key, $site->timezone)->utc();
            $bucketEnd = $hourly ? $bucketStart->copy()->addHour() : Carbon::parse($key, $site->timezone)->addDay()->utc();
            $coverage = $this->sessionCoverage($bucketStart, $bucketEnd, $history);
            $sessionsAvailable = ($row && $row->session_pageviews > 0) || $coverage !== 'unavailable';
            $availability = ! $sessionsAvailable && ($row->sessions ?? 0) === 0 ? 'unavailable' : (($row && ($row->unknown_pageviews > 0 || ($row->session_pageviews > 0 && $row->session_pageviews < $row->pageviews))) || $coverage === 'partial' ? 'partial' : 'available');
            $series[] = ['date' => $key, 'label' => $label, 'pageviews' => (int) ($row->pageviews ?? 0), 'custom_events' => (int) ($row->custom_events ?? 0), 'sessions' => $sessionsAvailable || ($row->sessions ?? 0) > 0 ? (int) ($row->sessions ?? 0) : null, 'session_availability' => $availability];
        }

        return ! empty($period['filters']) ? app(SegmentReports::class)->series($site, $period, $series) : $series;
    }

    public function sessionCoverage(Carbon $start, Carbon $end, array $history): string
    {
        $cursor = $start->copy();
        $enabled = $disabled = $unknown = false;
        foreach ($history as $entry) {
            $from = Carbon::parse($entry['started_at'], 'UTC')->max($start);
            $to = $entry['ended_at'] ? Carbon::parse($entry['ended_at'], 'UTC')->min($end) : $end;
            if ($from->gte($end) || $to->lte($start)) {
                continue;
            }
            $unknown = $unknown || $from->gt($cursor);
            $enabled = $enabled || $entry['mode'] === 'sessions';
            $disabled = $disabled || $entry['mode'] === 'pageviews';
            $cursor = $cursor->max($to);
        }
        $unknown = $unknown || $cursor->lt($end);

        return $enabled ? ($disabled || $unknown ? 'partial' : 'available') : 'unavailable';
    }

    public function measurementPeriods(Website $site, array $period): array
    {
        return DB::table('site_measurement_periods')->where('website_id', $site->id)->where('started_at', '<', $period['end'])
            ->where(fn ($query) => $query->whereNull('ended_at')->orWhere('ended_at', '>', $period['start']))
            ->orderBy('started_at')->get(['started_at', 'ended_at', 'mode'])->map(fn ($row) => (array) $row)->all();
    }
}
