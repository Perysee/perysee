<?php

namespace App\Analytics\Reporting;

use App\Analytics\Aggregation\EventPropertyStats;
use App\Models\Website;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The "event > property" report. It reads the daily property counters only, never a session or a visitor, and
 * ignores the report filters: the counters carry no other dimension.
 */
class EventPropertyReports
{
    public const VALUES = 50;

    public function query(Website $site, array $period): Builder
    {
        return DB::table('stats_event_property_daily')->where('website_id', $site->id)->whereBetween('stat_date', [$period['from'], $period['to']]);
    }

    /**
     * Events that carried properties in the period, most recorded first.
     *
     * @return list<array{name: string, keys: int, count: int}>
     */
    public function events(Website $site, array $period): array
    {
        return $this->query($site, $period)->select('event_name')->selectRaw('COUNT(DISTINCT prop_key) AS keys_count, SUM(metric_count) AS total')->groupBy('event_name')
            ->orderByDesc(DB::raw('SUM(metric_count)'))->orderBy('event_name')->limit(EventPropertyStats::MAX_NAMES + 1)->get()
            ->map(fn ($row): array => ['name' => $row->event_name, 'keys' => (int) $row->keys_count, 'count' => (int) $row->total])->all();
    }

    /**
     * The keys of one event with their most frequent values. What is not listed is summed under "(other)", so the
     * figures of a key always add up to its total.
     *
     * @return array<string, mixed>
     */
    public function report(Website $site, array $period, ?string $event): array
    {
        $events = $this->events($site, $period);
        $event ??= $events[0]['name'] ?? null;
        $keys = [];
        if ($event !== null) {
            $totals = $this->query($site, $period)->where('event_name', $event)->select('prop_key')->selectRaw('SUM(metric_count) AS total, SUM(value_sum) AS amount, SUM(valued_count) AS valued')
                ->groupBy('prop_key')->orderByDesc(DB::raw('SUM(metric_count)'))->orderBy('prop_key')->limit(EventPropertyStats::MAX_KEYS + 1)->get();
            foreach ($totals as $total) {
                $values = $this->query($site, $period)->where('event_name', $event)->where('prop_key', $total->prop_key)->where('prop_value', '!=', EventPropertyStats::OTHER)
                    ->selectRaw('MIN(prop_value) AS label, SUM(metric_count) AS total, SUM(value_sum) AS amount, SUM(valued_count) AS valued')->groupBy('value_hash')
                    ->orderByDesc(DB::raw('SUM(metric_count)'))->orderBy(DB::raw('MIN(prop_value)'))->limit(self::VALUES)->get();
                $rows = $values->map(fn ($row): array => $this->row($row->label, $row))->all();
                $rest = ['total' => (int) $total->total - (int) $values->sum('total'), 'amount' => (int) $total->amount - (int) $values->sum('amount'), 'valued' => (int) $total->valued - (int) $values->sum('valued')];
                if ($rest['total'] > 0) {
                    $rows[] = $this->row(EventPropertyStats::OTHER, (object) $rest);
                }
                $keys[] = ['key' => $total->prop_key, 'count' => (int) $total->total, 'value' => $this->money((int) $total->amount), 'values' => $rows];
            }
        }

        return ['event' => $event, 'events' => $events, 'keys' => $keys, 'currency' => $site->currency, 'limits' => ['values' => self::VALUES, 'keys' => EventPropertyStats::MAX_KEYS, 'events' => EventPropertyStats::MAX_NAMES]];
    }

    /**
     * @return array{value: string, count: int, amount: int|float, valued: int, average: float|null}
     */
    private function row(string $label, object $row): array
    {
        $valued = (int) $row->valued;

        return ['value' => $label, 'count' => (int) $row->total, 'amount' => $this->money((int) $row->amount), 'valued' => $valued, 'average' => $valued > 0 ? round((int) $row->amount / $valued / 1000, 2) : null];
    }

    private function money(int $thousandths): int|float
    {
        return round($thousandths / 1000, 3);
    }
}
