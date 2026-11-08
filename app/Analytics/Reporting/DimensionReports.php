<?php

namespace App\Analytics\Reporting;

use App\Analytics\Ingestion\Sources;
use App\Models\Website;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DimensionReports
{
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
}
