<?php

namespace App\Analytics\Reporting;

use App\Analytics\Ingestion\Sources;
use App\Analytics\InstanceHealth;
use App\Models\Website;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SegmentReports
{
    public function query(Website $site, array $period, bool $hourly = false): Builder
    {
        $query = DB::table($hourly ? 'stats_segment_hourly' : 'stats_segment_daily')->where('website_id', $site->id)->whereBetween('stat_date', [$period['from'], $period['to']]);
        app(Filters::class)->apply($query, $period['filters'] ?? []);
        if (($period['source_group'] ?? '') === 'ai') {
            $query->whereNotNull('provider');
        }
        if (! empty($period['provider'])) {
            $query->where('provider', $period['provider']);
        }

        return $query;
    }

    public function coverage(Website $site, array $period): array
    {
        $retention = (int) app(InstanceHealth::class)->get('raw_retention_days', config('perysee.raw_retention_days', 90));
        $pending = DB::table('events')->where('website_id', $site->id)->where('received_at', '>=', $period['start'])->where('received_at', '<', $period['end'])->whereNotExists(fn ($q) => $q->selectRaw('1')->from('segment_events')->whereColumn('event_id', 'events.id'))->exists();
        $old = Carbon::parse($period['from'], $site->timezone)->lt(now('UTC')->subDays($retention));
        $missingSessions = DB::table('measured_sessions')->where('website_id', $site->id)->where('first_seen_at', '>=', $period['start'])->where('first_seen_at', '<', $period['end'])->whereNotExists(fn ($q) => $q->selectRaw('1')->from('segment_sessions')->whereColumn('segment_sessions.website_id', 'measured_sessions.website_id')->whereColumn('segment_sessions.session_key', 'measured_sessions.session_key'))->count();
        $unmeasured = [];
        foreach (array_keys($period['filters'] ?? []) as $dimension) {
            if (DB::table('stats_segment_daily')->where('website_id', $site->id)->whereBetween('stat_date', [$period['from'], $period['to']])->whereNull($dimension)->where('pageviews', '>', 0)->exists()) {
                $unmeasured[] = $dimension;
            }
        }

        return ['status' => $pending ? 'rebuilding' : ($old || $unmeasured || $missingSessions ? 'partial' : 'available'), 'missing_sessions' => $missingSessions, 'raw_retention_days' => $retention, 'unmeasured_dimensions' => $unmeasured, 'message' => $pending ? 'Cross-filter reconstruction is pending. Counts are incomplete.' : ($old || $unmeasured || $missingSessions ? 'Some historical events or dimensions are unavailable. Counts cover measured data only.' : 'Cross-filter statistics cover retained, measured events.')];
    }

    public function overview(Website $site, array $period, array $base): array
    {
        $query = $this->query($site, $period);
        $totals = (clone $query)->selectRaw('SUM(pageviews) AS pageviews, SUM(custom_events) AS custom_events, SUM(sessions) AS sessions')->first();
        $eventOnly = ! empty($period['filters']['event']);
        $base['totals'] = ['pageviews' => $eventOnly ? null : (int) $totals->pageviews, 'custom_events' => (int) $totals->custom_events, 'sessions' => $eventOnly || $base['totals']['sessions'] === null ? null : (int) $totals->sessions];
        $base['segment_coverage'] = $this->coverage($site, $period);
        if ($base['segment_coverage']['missing_sessions'] > 0) {
            $base['totals']['sessions'] = null;
            $base['availability']['sessions'] = 'partial';
        }
        $base['filters'] = $period['filters'];
        $base['coverage']['pageviews'] = $base['totals']['pageviews'];
        $base['coverage']['measured_pageviews'] = (int) (clone $query)->whereNotNull('device')->sum('pageviews');
        $base['coverage']['country_pageviews'] = (int) (clone $query)->whereNotNull('country')->sum('pageviews');

        return $base;
    }

    public function series(Website $site, array $period, array $base): array
    {
        $hourly = $period['granularity'] === 'hour';
        $key = $hourly ? 'bucket_start' : 'stat_date';
        $rows = $this->query($site, $period, $hourly)->select($key)->selectRaw('SUM(pageviews) AS pageviews, SUM(custom_events) AS custom_events, SUM(sessions) AS sessions')->groupBy($key)->get()->keyBy($key);
        $missingSessionContext = $this->coverage($site, $period)['missing_sessions'] > 0;
        foreach ($base as &$point) {
            $row = $rows->get($point['date']);
            $point['pageviews'] = ! empty($period['filters']['event']) ? null : (int) ($row->pageviews ?? 0);
            $point['custom_events'] = (int) ($row->custom_events ?? 0);
            $point['sessions'] = $missingSessionContext || $point['sessions'] === null || ! empty($period['filters']['event']) ? null : (int) ($row->sessions ?? 0);
        }

        return $base;
    }

    public function dimensionReport(Website $site, array $period, string $type, ?string $value): array
    {
        if (! in_array($type, Filters::DIMENSIONS, true)) {
            throw ValidationException::withMessages(['dimension' => 'Unsupported dimension.']);
        }
        if ($value !== null && $value !== '') {
            $period['filters'][$type] = [$type === 'source' ? app(Sources::class)->describe($value)['value'] : $value];
        }
        $metric = $type === 'event' ? 'custom_events' : 'pageviews';
        $query = $this->query($site, $period)->whereNotNull($type)->where($metric, '>', 0);
        $rows = (clone $query)->select($type.' AS value')->selectRaw('SUM('.$metric.') AS count')->groupBy($type)->orderByDesc('count')->orderBy($type)->limit($type === 'country' ? 300 : 100)->get()->map(function ($row) use ($type): array {
            $result = ['value' => $row->value, 'count' => (int) $row->count];

            return $type === 'source' ? $result + app(Sources::class)->describe($row->value) : $result;
        })->all();
        $daily = (clone $query)->select('stat_date')->selectRaw('SUM('.$metric.') AS count')->groupBy('stat_date')->get()->keyBy('stat_date');
        $series = [];
        for ($day = Carbon::parse($period['from']); $day->toDateString() <= $period['to']; $day->addDay()) {
            $date = $day->toDateString();
            $count = (int) ($daily->get($date)->count ?? 0);
            $series[] = ['date' => $date, 'label' => $date, 'pageviews' => $type === 'event' ? 0 : $count, 'custom_events' => $type === 'event' ? $count : 0, 'sessions' => null];
        }

        return ['map' => $type === 'country' ? $rows : [], 'total' => (int) (clone $query)->sum($metric), 'distinct_values' => (clone $query)->distinct()->count($type), 'granularity' => 'day', 'series' => $series, 'data' => array_slice($rows, 0, 100)];
    }

    public function options(Website $site, array $period, string $dimension, string $search): array
    {
        unset($period['filters'][$dimension]);

        return $this->query($site, $period)->whereNotNull($dimension)->when($search !== '', fn ($q) => $q->whereRaw('`'.$dimension.'` LIKE ? ESCAPE \'!\'', ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%']))->select($dimension.' AS value')->distinct()->orderBy($dimension)->limit(100)->pluck('value')->all();
    }
}
