<?php

namespace App\Analytics\Reporting;

use App\Analytics\Ingestion\Sources;
use App\Analytics\InstanceHealth;
use App\Models\Website;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Reports
{
    public function period(Request $request, Website $site): array
    {
        $input = $request->validate([
            'period' => ['sometimes', 'in:today,7d,30d'],
            'from' => ['nullable', 'required_with:to', 'date_format:Y-m-d'],
            'to' => ['nullable', 'required_with:from', 'date_format:Y-m-d', 'after_or_equal:from'],
            'dimension' => ['sometimes', 'in:path,source,campaign,device,event,country,browser,device_brand'],
            'source_group' => ['sometimes', 'in:all,ai'], 'provider' => ['nullable', 'in:chatgpt,claude,perplexity,gemini,copilot,grok'],
            'value' => ['nullable', 'string', 'max:512'], 'granularity' => ['sometimes', 'in:day,hour'],
            'compare' => ['sometimes', 'in:previous'],
        ]);
        $today = now($site->timezone)->startOfDay();
        $days = match ($input['period'] ?? '7d') {
            'today' => 0, '30d' => 29, default => 6
        };
        $from = $input['from'] ?? $today->copy()->subDays($days)->toDateString();
        $to = $input['to'] ?? $today->toDateString();
        $start = Carbon::createFromFormat('!Y-m-d', $from, $site->timezone);
        $end = Carbon::createFromFormat('!Y-m-d', $to, $site->timezone);
        if ($start->gt($end) || $end->gt($today) || $start->diffInDays($end) > 1095) {
            throw ValidationException::withMessages(['from' => 'Choose an ordered date range of at most three years, ending today or earlier.']);
        }
        if (($input['granularity'] ?? 'day') === 'hour' && $from !== $to) {
            throw ValidationException::withMessages(['granularity' => 'Hourly reports require one local calendar day.']);
        }

        return ['from' => $from, 'to' => $to, 'start' => $start->copy()->utc(), 'end' => $end->copy()->addDay()->utc(), 'granularity' => $input['granularity'] ?? ($from === $to ? 'hour' : 'day'), 'filters' => app(Filters::class)->parse($request), 'source_group' => $input['source_group'] ?? 'all', 'provider' => $input['provider'] ?? null, 'compare' => $input['compare'] ?? null];
    }

    /**
     * The period of the same length that ends the day before $period starts, with the same filters.
     *
     * @param  array<string, mixed>  $period
     * @return array<string, mixed>
     */
    public function previousPeriod(array $period, Website $site): array
    {
        $start = Carbon::createFromFormat('!Y-m-d', $period['from'], $site->timezone);
        $end = Carbon::createFromFormat('!Y-m-d', $period['to'], $site->timezone);
        $days = (int) $start->diffInDays($end) + 1;
        $previousEnd = $start->copy()->subDay();
        $previousStart = $previousEnd->copy()->subDays($days - 1);

        return [
            'from' => $previousStart->toDateString(), 'to' => $previousEnd->toDateString(),
            'start' => $previousStart->copy()->utc(), 'end' => $previousEnd->copy()->addDay()->utc(),
            'granularity' => $days === 1 ? 'hour' : 'day',
        ] + $period;
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
        $current ??= $this->overview($site, $period);
        $previous = $this->previousPeriod($period, $site);
        if ($totalsOnly && ($current['freshness']['pending'] ?? false)) {
            $reliability = array_fill_keys(['pageviews', 'sessions', 'custom_events'], ['percent_allowed' => false, 'reason' => 'rebuilding']);

            return ['period' => ['from' => $previous['from'], 'to' => $previous['to']], 'totals' => null, 'reliability' => $reliability];
        }
        $known = [
            'rows' => DB::table('stats_daily')->where('website_id', $site->id)->whereBetween('stat_date', [$previous['from'], $previous['to']])->get(),
            'history' => $this->measurementPeriods($site, $previous),
            'freshness' => $current['freshness'],
        ];
        $overview = $this->overview($site, $previous, $known);

        return [
            'period' => ['from' => $previous['from'], 'to' => $previous['to']],
            'totals' => $overview['totals'],
            'series' => $totalsOnly ? [] : $this->alignSeries($site, $previous, $period, $this->series($site, $previous, $known)),
            'availability' => $overview['availability'],
            'coverage' => $overview['coverage'],
            'freshness' => $overview['freshness'],
            ...(isset($overview['segment_coverage']) ? ['segment_coverage' => $overview['segment_coverage']] : []),
            'reliability' => $this->reliability($site, $period, $current, $overview),
        ];
    }

    /**
     * @return array<string, array{percent_allowed: bool, reason: string|null}>
     */
    private function reliability(Website $site, array $period, array $current, array $previous): array
    {
        $includesToday = $period['to'] >= now($site->timezone)->toDateString();
        $sides = [$current, $previous];
        $rebuilding = ($current['freshness']['pending'] ?? false) || collect($sides)->contains(fn (array $side): bool => ($side['segment_coverage']['status'] ?? null) === 'rebuilding');
        $sessionsGone = collect($sides)->contains(fn (array $side): bool => $side['availability']['sessions'] === 'unavailable');
        $sessionsPartial = collect($sides)->contains(fn (array $side): bool => $side['availability']['sessions'] === 'partial' || ($side['segment_coverage']['missing_sessions'] ?? 0) > 0);
        $verdict = [];
        foreach (['pageviews', 'sessions', 'custom_events'] as $metric) {
            $sessions = $metric === 'sessions';
            $reason = match (true) {
                $metric !== 'custom_events' && ! empty($period['filters']['event']) => 'not_applicable',
                $rebuilding => 'rebuilding',
                $sessions && $sessionsGone => 'unavailable',
                $sessions && $sessionsPartial => 'partial_coverage',
                $sessions && ($current['totals'][$metric] === null || $previous['totals'][$metric] === null) => 'unavailable',
                $includesToday => 'current_period',
                ($previous['totals'][$metric] ?? 0) === 0 => 'previous_zero',
                default => null,
            };
            $verdict[$metric] = ['percent_allowed' => $reason === null, 'reason' => $reason];
        }

        return $verdict;
    }

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
    private function bucketKeys(Website $site, array $period): array
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
            foreach (array_values($this->bucketKeys($site, $current)) as $rank => $label) {
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

    private function dimensionExpression(string $type): string
    {
        if ($type !== 'source') {
            return 'dimension_value';
        }
        $expression = 'CASE';
        foreach (Sources::PROVIDERS as $entry) {
            $aliases = implode(',', array_map(fn (string $alias): string => "'".$alias."'", $entry['aliases']));
            $expression .= ' WHEN LOWER(dimension_value) IN ('.$aliases.") THEN '".$entry['key']."'";
        }

        return $expression.' ELSE dimension_value END';
    }

    private function dimensionQuery(Website $site, array $period, string $type, ?string $value): Builder
    {
        $query = DB::table('stats_dimension_daily')->where('website_id', $site->id)->whereBetween('stat_date', [$period['from'], $period['to']])->where('dimension_type', $type);
        $expression = $this->dimensionExpression($type);
        if ($type === 'source' && (($period['source_group'] ?? 'all') === 'ai' || ! empty($period['provider']))) {
            $providers = empty($period['provider']) ? Sources::PROVIDERS : array_intersect_key(Sources::PROVIDERS, [$period['provider'] => true]);
            $query->whereIn(DB::raw($expression), array_column($providers, 'key'));
        }
        if ($value !== null && $value !== '') {
            $query->whereRaw('('.$expression.') = ?', [$type === 'source' ? app(Sources::class)->describe($value)['value'] : $value]);
        }

        return $query;
    }

    public function dimensions(Website $site, array $period, string $type = 'path', ?string $value = null): array
    {
        if (! empty($period['filters'])) {
            return app(SegmentReports::class)->dimensionReport($site, $period, $type, $value)['data'];
        }

        return $this->topDimensions($site, $period, $type, $value)['data'];
    }

    /**
     * The 100 largest values with the number of distinct values behind them, from one grouped query (the window counts the groups before the limit).
     *
     * @return array{data: list<array<string, mixed>>, distinct: int}
     */
    private function topDimensions(Website $site, array $period, string $type, ?string $value): array
    {
        $expression = $this->dimensionExpression($type);
        $rows = $this->dimensionQuery($site, $period, $type, $value)->selectRaw($expression.' AS dimension_value, SUM(metric_count) AS count, COUNT(*) OVER () AS distinct_values')->groupByRaw($expression)->orderByDesc('count')->orderBy('dimension_value')->limit(100)->get();

        return ['distinct' => (int) ($rows->first()->distinct_values ?? 0), 'data' => $rows->map(function ($row) use ($type): array {
            $result = ['value' => $row->dimension_value, 'count' => (int) $row->count];

            return $type === 'source' ? $result + app(Sources::class)->describe($row->dimension_value) : $result;
        })->all()];
    }

    public function dimensionReport(Website $site, array $period, string $type, ?string $value = null): array
    {
        if (! empty($period['filters'])) {
            return app(SegmentReports::class)->dimensionReport($site, $period, $type, $value);
        }
        $query = $this->dimensionQuery($site, $period, $type, $value);
        $daily = (clone $query)->select('stat_date')->selectRaw('SUM(metric_count) AS count')->groupBy('stat_date')->get()->keyBy('stat_date');
        $top = $this->topDimensions($site, $period, $type, $value);
        $series = [];
        for ($day = Carbon::parse($period['from']); $day->toDateString() <= $period['to']; $day->addDay()) {
            $date = $day->toDateString();
            $count = (int) ($daily->get($date)->count ?? 0);
            $series[] = ['date' => $date, 'label' => $date, 'pageviews' => $type === 'event' ? 0 : $count, 'custom_events' => $type === 'event' ? $count : 0, 'sessions' => null];
        }

        return ['map' => $type === 'country' ? (clone $query)->select('dimension_value AS value')->selectRaw('SUM(metric_count) AS count')->groupBy('dimension_value')->limit(300)->get()->map(fn ($row) => ['value' => $row->value, 'count' => (int) $row->count])->all() : [], 'total' => (int) $daily->sum('count'), 'distinct_values' => $top['distinct'], 'granularity' => 'day', 'series' => $series, 'data' => $top['data']];
    }

    public function clientCoverage(Website $site, array $period): array
    {
        if (! empty($period['filters'])) {
            $coverage = [];
            foreach (['device', 'device_brand', 'browser'] as $dimension) {
                $coverage[$dimension] = (int) app(SegmentReports::class)->query($site, $period)->whereNotNull($dimension)->sum('pageviews');
            }

            return $coverage;
        }
        $totals = DB::table('stats_dimension_daily')->where('website_id', $site->id)
            ->whereBetween('stat_date', [$period['from'], $period['to']])
            ->whereIn('dimension_type', ['device', 'device_brand', 'browser'])
            ->select('dimension_type')->selectRaw('SUM(metric_count) AS total')->groupBy('dimension_type')->pluck('total', 'dimension_type');

        return array_map(fn (string $type): int => (int) ($totals[$type] ?? 0), array_combine(['device', 'device_brand', 'browser'], ['device', 'device_brand', 'browser']));
    }

    public function receptionTotals(Website $site): array
    {
        $row = DB::table('events')->where('website_id', $site->id)->where('received_at', '>=', now('UTC')->subMinutes(15))
            ->selectRaw('COUNT(*) AS events, SUM(CASE WHEN event_type = 1 THEN 1 ELSE 0 END) AS pageviews, SUM(CASE WHEN event_type = 2 THEN 1 ELSE 0 END) AS custom_events')->first();

        return ['events' => (int) $row->events, 'pageviews' => (int) $row->pageviews, 'custom_events' => (int) $row->custom_events];
    }

    private function sessionCoverage(Carbon $start, Carbon $end, array $history): string
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

    public function recent(Website $site): array
    {
        return DB::table('events')->where('website_id', $site->id)->where('received_at', '>=', now('UTC')->subMinutes(15))->orderByDesc('received_at')->orderByDesc('id')->limit(100)->get(['received_at', 'event_type', 'event_name', 'path'])->map(fn ($event) => (array) $event)->all();
    }
}
