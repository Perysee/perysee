<?php

namespace App\Analytics\Aggregation;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Counts the properties of named events per day, with the number of distinct names, keys and values bounded so
 * that a badly configured site cannot grow the table without limit. What exceeds a bound is counted under
 * "(other)": the totals stay exact, only the detail is lost.
 *
 * Bounds: 100 event names per site, 10 keys per event name, 50 values per day, event name and key. Events are
 * admitted in the order of their id, so one batch is deterministic; a rebuild in another order can admit other
 * values (ADR 008).
 */
class EventPropertyStats
{
    public const MAX_NAMES = 100;

    public const MAX_KEYS = 10;

    public const MAX_VALUES = 50;

    public const OTHER = '(other)';

    /**
     * @var list<array{0: object, 1: int, 2: string}>|null
     */
    private ?array $held = null;

    public function begin(): void
    {
        $this->held = [];
    }

    /**
     * Holds an event that carries properties; nothing else costs anything.
     */
    public function ingest(object $event, object $site, string $date): void
    {
        if ($this->held !== null && $event->event_type === 2 && ($event->event_props ?? null) !== null && $event->event_name !== null) {
            $this->held[] = [$event, $site->id, $date];
        }
    }

    /**
     * Writes the held properties with two reads and one write per chunk of 200 rows.
     */
    public function flush(): void
    {
        $held = $this->held;
        $this->held = null;
        if ($held === null || $held === []) {
            return;
        }
        $sites = array_values(array_unique(array_map(fn (array $item): int => $item[1], $held)));
        $names = array_values(array_unique(array_map(fn (array $item): string => $item[0]->event_name, $held)));
        // Known names and keys, over the whole retained period.
        $knownNames = $knownKeys = [];
        foreach (array_chunk($names, 500) as $chunk) {
            foreach (DB::table('stats_event_property_daily')->whereIn('website_id', $sites)->whereIn('event_name', [...$chunk, self::OTHER])->select('website_id', 'event_name', 'prop_key')->distinct()->get() as $row) {
                $knownNames[$row->website_id][$row->event_name] = true;
                $knownKeys[$row->website_id.'|'.$row->event_name][$row->prop_key] = true;
            }
        }
        // Names of the sites that are not in this batch also count toward the bound.
        foreach ($sites as $site) {
            $knownNames[$site] ??= [];
        }
        $lines = [];
        foreach ($held as [$event, $site, $date]) {
            $props = json_decode($event->event_props, true, flags: JSON_THROW_ON_ERROR);
            $name = $event->event_name;
            if (! isset($knownNames[$site][$name])) {
                if ($this->count($knownNames[$site]) >= self::MAX_NAMES) {
                    $name = self::OTHER;
                }
                $knownNames[$site][$name] = true;
            }
            foreach ($props as $key => $value) {
                $slot = $site.'|'.$name;
                if (! isset($knownKeys[$slot][$key])) {
                    if ($this->count($knownKeys[$slot] ?? []) >= self::MAX_KEYS) {
                        $key = self::OTHER;
                        $value = self::OTHER;
                    }
                    $knownKeys[$slot][$key] = true;
                }
                $lines[] = [$site, $date, $name, (string) $key, (string) $value, $event->event_value === null ? null : (int) $event->event_value];
            }
        }
        // The values already stored for the touched tuples decide whether a new value is still admitted. One read
        // for all sites: the lists may match more rows than needed, never more than the bounds allow.
        $values = [];
        $stored = [];
        $touchedSites = array_values(array_unique(array_column($lines, 0)));
        $touchedDates = array_values(array_unique(array_column($lines, 1)));
        $touchedNames = array_values(array_unique(array_column($lines, 2)));
        $touchedKeys = array_values(array_unique(array_column($lines, 3)));
        foreach (DB::table('stats_event_property_daily')->whereIn('website_id', $touchedSites)->whereIn('stat_date', $touchedDates)->whereIn('event_name', $touchedNames)->whereIn('prop_key', $touchedKeys)->get(['website_id', 'stat_date', 'event_name', 'prop_key', 'value_hash', 'prop_value']) as $row) {
            $stored[implode('|', [$row->website_id, $row->stat_date, $row->event_name, $row->prop_key, $row->value_hash])] = $row->prop_value;
            if ($row->prop_value !== self::OTHER) {
                $values[implode('|', [$row->website_id, $row->stat_date, $row->event_name, $row->prop_key])][$row->value_hash] = true;
            }
        }
        $rows = [];
        foreach ($lines as [$site, $date, $name, $key, $value, $amount]) {
            $slot = implode('|', [$site, $date, $name, $key]);
            $hash = hash('sha256', $value);
            if ($value !== self::OTHER && ! isset($values[$slot][$hash])) {
                if ($this->count($values[$slot] ?? []) >= self::MAX_VALUES) {
                    $value = self::OTHER;
                    $hash = hash('sha256', $value);
                } else {
                    $values[$slot][$hash] = true;
                }
            }
            $identity = $slot.'|'.$hash;
            if (isset($stored[$identity]) && $stored[$identity] !== $value) {
                throw new RuntimeException('Property identity collision.');
            }
            $rows[$identity] ??= ['website_id' => $site, 'stat_date' => $date, 'event_name' => $name, 'prop_key' => $key, 'value_hash' => $hash, 'prop_value' => $value, 'metric_count' => 0, 'value_sum' => 0, 'valued_count' => 0];
            $rows[$identity]['metric_count']++;
            if ($amount !== null) {
                $rows[$identity]['value_sum'] += $amount;
                $rows[$identity]['valued_count']++;
            }
        }
        $expressions = [];
        foreach (['metric_count', 'value_sum', 'valued_count'] as $metric) {
            $expressions[$metric] = DB::raw($metric.' + '.(DB::getDriverName() === 'sqlite' ? 'excluded.'.$metric : 'VALUES('.$metric.')'));
        }
        foreach (array_chunk(array_values($rows), 200) as $chunk) {
            DB::table('stats_event_property_daily')->upsert($chunk, ['website_id', 'stat_date', 'event_name', 'prop_key', 'value_hash'], $expressions);
        }
    }

    /**
     * Distinct entries of a set that does not count the overflow bucket.
     *
     * @param  array<string, mixed>  $set
     */
    private function count(array $set): int
    {
        return count($set) - (isset($set[self::OTHER]) ? 1 : 0);
    }
}
