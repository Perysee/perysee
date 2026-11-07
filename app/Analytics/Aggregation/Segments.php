<?php

namespace App\Analytics\Aggregation;

use App\Analytics\InstanceHealth;
use App\Analytics\Reporting\Filters;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class Segments
{
    private const METRICS = ['pageviews', 'custom_events', 'sessions'];

    /**
     * Writes held back while a batch is open; null when every write is immediate.
     *
     * @var array{pending: list<array<string, mixed>>, known: array<int, true>, sessions: array<string, object>, dirty: array<string, true>, counters: array<string, array<string, array<string, mixed>>>}|null
     */
    private ?array $batch = null;

    /**
     * Reads a session's first measured time from the caller's in-memory state while a batch is open.
     *
     * @var (Closure(int, string): ?string)|null
     */
    private ?Closure $firstSeen = null;

    /**
     * Opens a batch: the existing segment state of these events is read once and every write is held until flush().
     *
     * @param  Collection<int, object>  $events
     * @param  (Closure(int, string): ?string)|null  $firstSeen  Reads a measured session's first time; it is read from the database once for the batch when null.
     */
    public function begin(Collection $events, ?Closure $firstSeen = null): void
    {
        $known = [];
        $sessions = [];
        $measured = [];
        if ($events->isNotEmpty()) {
            $known = array_fill_keys(DB::table('segment_events')->whereIn('event_id', $events->pluck('id'))->pluck('event_id')->all(), true);
            $sites = $events->pluck('website_id')->unique()->values();
            foreach ($events->pluck('session_key')->filter()->unique()->chunk(500) as $keys) {
                foreach (DB::table('segment_sessions')->whereIn('website_id', $sites)->whereIn('session_key', $keys->values())->get() as $row) {
                    $sessions[$row->website_id.'|'.$row->session_key] = $row;
                }
                if ($firstSeen === null) {
                    foreach (DB::table('measured_sessions')->whereIn('website_id', $sites)->whereIn('session_key', $keys->values())->get(['website_id', 'session_key', 'first_seen_at']) as $row) {
                        $measured[$row->website_id.'|'.$row->session_key] = $row->first_seen_at;
                    }
                }
            }
        }
        $this->firstSeen = $firstSeen ?? fn (int $site, string $key): ?string => $measured[$site.'|'.$key] ?? null;
        $this->batch = ['pending' => [], 'known' => $known, 'sessions' => $sessions, 'dirty' => [], 'counters' => []];
    }

    /**
     * Writes the held events, sessions and counters with one statement per table and chunk.
     */
    public function flush(): void
    {
        $batch = $this->batch;
        $this->batch = null;
        $this->firstSeen = null;
        if ($batch === null) {
            return;
        }
        foreach (array_chunk($batch['pending'], 500) as $rows) {
            DB::table('segment_events')->insertOrIgnore($rows);
        }
        $sessions = array_map(fn (string $slot): array => (array) $batch['sessions'][$slot], array_keys($batch['dirty']));
        foreach (array_chunk($sessions, 200) as $rows) {
            DB::table('segment_sessions')->upsert($rows, ['website_id', 'session_key'], ['first_event_id', 'first_seen_at', 'event_data']);
        }
        foreach ($batch['counters'] as $table => $rows) {
            $this->flushCounters($table, $rows);
        }
    }

    /**
     * The first event of a measured session as the open batch currently knows it, or null.
     */
    public function firstEvent(int $site, string $key): ?object
    {
        $row = $this->batch['sessions'][$site.'|'.$key] ?? null;

        return $row ? (object) json_decode($row->event_data, true, flags: JSON_THROW_ON_ERROR) : null;
    }

    /**
     * @return bool False when the event had already been ingested.
     */
    public function ingest(object $event, object $site): bool
    {
        if ($this->batch !== null) {
            if (isset($this->batch['known'][$event->id])) {
                return false;
            }
            $this->batch['known'][$event->id] = true;
            $this->batch['pending'][] = ['event_id' => $event->id, 'website_id' => $site->id, 'received_at' => $event->received_at];
        } elseif (! DB::table('segment_events')->insertOrIgnore(['event_id' => $event->id, 'website_id' => $site->id, 'received_at' => $event->received_at])) {
            return false;
        }
        $this->increment($event, $site, $event->event_type === 1 ? 'pageviews' : 'custom_events', 1);
        if ($event->event_type !== 1 || ! $event->session_key) {
            return true;
        }
        $identity = ['website_id' => $site->id, 'session_key' => $event->session_key];
        $slot = $site->id.'|'.$event->session_key;
        $first = $this->batch !== null ? ($this->batch['sessions'][$slot] ?? null) : DB::table('segment_sessions')->where($identity)->first();
        if ($first && [Carbon::parse($first->first_seen_at)->getTimestampMs(), $first->first_event_id] <= [Carbon::parse($event->received_at)->getTimestampMs(), $event->id]) {
            return true;
        }
        if ($first) {
            $this->increment((object) json_decode($first->event_data, true, flags: JSON_THROW_ON_ERROR), $site, 'sessions', -1);
        }
        $known = $this->firstSeen !== null ? ($this->firstSeen)($site->id, $event->session_key) : DB::table('measured_sessions')->where($identity)->value('first_seen_at');
        if ($known && Carbon::parse($known)->lt(Carbon::parse($event->received_at))) {
            return true;
        }
        $this->increment($event, $site, 'sessions', 1);
        $values = ['first_event_id' => $event->id, 'first_seen_at' => $event->received_at, 'event_data' => json_encode($event, JSON_THROW_ON_ERROR)];
        if ($this->batch !== null) {
            $this->batch['sessions'][$slot] = (object) ($identity + $values);
            $this->batch['dirty'][$slot] = true;

            return true;
        }
        DB::table('segment_sessions')->updateOrInsert($identity, $values);

        return true;
    }

    private function increment(object $event, object $site, string $metric, int $delta): void
    {
        $dimensions = app(Filters::class)->values($event);
        $json = json_encode($dimensions, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $local = Carbon::parse($event->received_at, 'UTC')->setTimezone($site->timezone);
        foreach (['stats_segment_daily' => $local->copy()->startOfDay(), 'stats_segment_hourly' => $local->copy()->startOfHour()] as $table => $bucket) {
            $identity = ['website_id' => $site->id, 'bucket_start' => $bucket->utc()->toDateTimeString(), 'segment_hash' => hash('sha256', $json)];
            if ($this->batch !== null) {
                $key = implode('|', $identity);
                $this->batch['counters'][$table][$key] ??= ['identity' => $identity, 'json' => $json, 'insert' => $identity + $dimensions + ['stat_date' => $local->toDateString(), 'dimensions' => $json], 'metrics' => array_fill_keys(self::METRICS, 0)];
                $this->batch['counters'][$table][$key]['metrics'][$metric] += $delta;

                continue;
            }
            DB::table($table)->insertOrIgnore($identity + $dimensions + ['stat_date' => $local->toDateString(), 'dimensions' => $json]);
            $query = DB::table($table)->where($identity)->where('dimensions', $json);
            if ($delta < 0 && (int) (clone $query)->value($metric) < -$delta) {
                throw new RuntimeException('Segment session attribution cannot move outside retained statistics.');
            }
            if ($query->increment($metric, $delta) !== 1) {
                throw new RuntimeException('Segment identity collision.');
            }
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $rows
     */
    private function flushCounters(string $table, array $rows): void
    {
        $existing = [];
        $sites = array_values(array_unique(array_column(array_column($rows, 'identity'), 'website_id')));
        foreach (array_chunk(array_values(array_unique(array_column(array_column($rows, 'identity'), 'segment_hash'))), 500) as $hashes) {
            foreach (DB::table($table)->whereIn('website_id', $sites)->whereIn('segment_hash', $hashes)->get() as $row) {
                $existing[implode('|', [$row->website_id, $row->bucket_start, $row->segment_hash])] = $row;
            }
        }
        $additions = [];
        foreach ($rows as $key => $row) {
            $current = $existing[$key] ?? null;
            if ($current && $current->dimensions !== $row['json']) {
                throw new RuntimeException('Segment identity collision.');
            }
            if (min($row['metrics']) >= 0) {
                $additions[] = $row['insert'] + $row['metrics'];

                continue;
            }
            foreach ($row['metrics'] as $metric => $delta) {
                if ($delta < 0 && (int) ($current->{$metric} ?? 0) < -$delta) {
                    throw new RuntimeException('Segment session attribution cannot move outside retained statistics.');
                }
            }
            DB::table($table)->insertOrIgnore($row['insert']);
            DB::table($table)->where($row['identity'])->incrementEach(array_filter($row['metrics']));
        }
        $expressions = [];
        foreach (self::METRICS as $metric) {
            $expressions[$metric] = DB::raw($metric.' + '.(DB::getDriverName() === 'sqlite' ? 'excluded.'.$metric : 'VALUES('.$metric.')'));
        }
        foreach (array_chunk($additions, 200) as $chunk) {
            DB::table($table)->upsert($chunk, ['website_id', 'bucket_start', 'segment_hash'], $expressions);
        }
    }

    /**
     * Rebuilds the segments of processed events that were never ingested. Once a run leaves nothing pending, the
     * highest event id it covered is remembered: the aggregator ingests every later event itself, so a scan
     * is only repeated when newer events exist, and then only over them.
     */
    public function rebuild(int $limit = 500): int
    {
        $state = app(InstanceHealth::class)->get('segments_rebuild');
        $through = ($state['pending'] ?? null) === 0 ? (int) ($state['through'] ?? 0) : 0;
        if ($through > 0 && (int) DB::table('events')->max('id') <= $through) {
            return 0;
        }
        $maria = in_array(DB::getDriverName(), ['mariadb', 'mysql'], true);
        $name = 'perysee:aggregate:'.substr(hash('sha256', DB::connection()->getDatabaseName()), 0, 32);
        if ($maria && (int) DB::selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$name])->acquired !== 1) {
            return 0;
        }
        try {
            return DB::transaction(function () use ($limit, $through): int {
                $latest = (int) DB::table('events')->max('id');
                $missing = fn () => DB::table('events')->where('id', '>', $through)->whereNotNull('processed_at')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('segment_events')->whereColumn('event_id', 'events.id'));
                $rows = $missing()->orderBy('id')->limit($limit)->get();
                $sites = DB::table('websites')->whereIn('id', $rows->pluck('website_id')->unique())->whereNull('deleting_at')->get()->keyBy('id');
                $this->begin($rows);
                foreach ($rows as $event) {
                    if ($site = $sites->get($event->website_id)) {
                        $this->ingest($event, $site);
                    }
                }
                $this->flush();
                $pending = $rows->count() < $limit ? 0 : $missing()->count();
                app(InstanceHealth::class)->put('segments_rebuild', ['at' => now('UTC')->toIso8601String(), 'processed' => $rows->count(), 'pending' => $pending, 'through' => $pending === 0 ? $latest : 0]);

                return $rows->count();
            }, 3);
        } finally {
            if ($maria) {
                DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [$name]);
            }
        }
    }
}
