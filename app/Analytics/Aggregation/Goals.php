<?php

namespace App\Analytics\Aggregation;

use App\Analytics\Continuity\ContinuityLog;
use App\Analytics\InstanceHealth;
use App\Analytics\Reporting\Filters;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Counts goal conversions: a measured session converts at most once per goal version, on the event that reaches
 * the goal's threshold, and is attributed to the dimensions of its first event (first touch).
 */
class Goals
{
    private const BACKFILL = 'goals_backfill:';

    private const SESSION_KEY = ['website_id', 'goal_id', 'version', 'session_key'];

    /**
     * @var array{items: list<array{0: object, 1: object}>, goals: array<int, list<object>>}|null
     */
    private ?array $batch = null;

    /**
     * New conversions counted live by the current batch, by goal version (for the events modules can listen to).
     *
     * @var array<string, array{site: int, goal: int, version: int, count: int}>
     */
    private array $converted = [];

    /**
     * Opens a batch: the active goals of the sites involved are read once. Sites without a goal cost nothing more.
     *
     * @param  Collection<int, object>  $events
     */
    public function begin(Collection $events): void
    {
        $this->converted = [];
        $this->batch = ['items' => [], 'goals' => $events->isEmpty() ? [] : $this->active($events->pluck('website_id')->unique()->values()->all())];
    }

    /**
     * Holds an event that the segments just accepted; it is counted by flush().
     */
    public function ingest(object $event, object $site): void
    {
        if ($this->batch !== null && isset($this->batch['goals'][$site->id])) {
            $this->batch['items'][] = [$event, $site];
        }
    }

    /**
     * Writes the held conversions with one read and one write per table and chunk. It must run while the
     * segments still hold the batch, because the first event of a session is read from them.
     */
    public function flush(Segments $segments): void
    {
        $batch = $this->batch;
        $this->batch = null;
        if ($batch === null || $batch['items'] === []) {
            return;
        }
        $this->process($batch['items'], $batch['goals'], fn (int $site, string $key): ?object => $segments->firstEvent($site, $key), true);
    }

    /**
     * The conversions the last flush() counted that were not there before. A conversion that only moved to another day nets
     * out at zero and is left out; a backfill or a rebuild never contributes.
     *
     * @return list<array{site: int, goal: int, version: int, count: int}>
     */
    public function conversions(): array
    {
        return array_values(array_filter($this->converted, fn (array $row): bool => $row['count'] > 0));
    }

    /**
     * Queues the historical count of a goal version over the events that existed when it was defined.
     */
    public function queueBackfill(int $site, int $goal, int $version, int $snapshot): void
    {
        if ($snapshot > 0) {
            app(InstanceHealth::class)->put(self::BACKFILL.$goal.':'.$version, ['site' => $site, 'cursor' => 0, 'snapshot' => $snapshot]);
        }
    }

    /**
     * Whether a goal version is still being counted over past events.
     */
    public function backfilling(int $goal, int $version): bool
    {
        return DB::table('analytics_state')->where('name', self::BACKFILL.$goal.':'.$version)->exists();
    }

    /**
     * Counts one bounded batch of the oldest queued backfill, under the aggregator's lock. Idle cost is one read.
     */
    public function rebuild(int $limit = 500): int
    {
        $job = DB::table('analytics_state')->where('name', 'like', self::BACKFILL.'%')->orderBy('name')->first();
        if (! $job) {
            return 0;
        }
        $maria = in_array(DB::getDriverName(), ['mariadb', 'mysql'], true);
        $name = 'perysee:aggregate:'.substr(hash('sha256', DB::connection()->getDatabaseName()), 0, 32);
        if ($maria && (int) DB::selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$name])->acquired !== 1) {
            return 0;
        }
        try {
            return DB::transaction(function () use ($job, $limit): int {
                $state = json_decode($job->value, true, flags: JSON_THROW_ON_ERROR);
                [, $goalId, $version] = explode(':', $job->name);
                $site = DB::table('websites')->where('id', $state['site'])->whereNull('deleting_at')->first();
                $goal = $site ? $this->version((int) $goalId, (int) $version) : null;
                if (! $goal) {
                    DB::table('analytics_state')->where('name', $job->name)->delete();

                    return 0;
                }
                $query = DB::table('events')->where('website_id', $site->id)->where('id', '>', $state['cursor'])->where('id', '<=', $state['snapshot'])
                    ->where('event_type', $goal->type === 'event' ? 2 : 1)->where($goal->type === 'event' ? 'event_name' : 'path', $goal->value);
                $rows = $query->orderBy('id')->limit($limit)->get();
                $firsts = $this->firstEvents($rows);
                $this->process($rows->map(fn ($event): array => [$event, $site])->all(), [$site->id => [$goal]], fn (int $s, string $key): ?object => $firsts[$s.'|'.$key] ?? null, false);
                if ($rows->count() < $limit) {
                    DB::table('analytics_state')->where('name', $job->name)->delete();
                    // The goal now counts its past (up to the snapshot taken when it was defined).
                    app(ContinuityLog::class)->record('rebuild', $site->id, ContinuityLog::NOTE, 'goals', ['target' => 'goals', 'goal_id' => (int) $goalId, 'version' => (int) $version, 'through' => (int) $state['snapshot']], now($site->timezone)->toDateString());
                } else {
                    app(InstanceHealth::class)->put($job->name, ['cursor' => $rows->last()->id] + $state);
                }

                return $rows->count();
            }, 3);
        } finally {
            if ($maria) {
                DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [$name]);
            }
        }
    }

    /**
     * The current definition of every active goal of these sites, grouped by site.
     *
     * @param  list<int>  $sites
     * @return array<int, list<object>>
     */
    private function active(array $sites): array
    {
        $goals = [];
        $rows = DB::table('goals')->join('goal_versions', fn ($join) => $join->on('goal_versions.goal_id', '=', 'goals.id')->on('goal_versions.version', '=', 'goals.current_version'))
            ->whereIn('goals.website_id', $sites)->whereNull('goals.archived_at')
            ->get(['goals.website_id', 'goals.id as goal_id', 'goal_versions.version', 'goal_versions.type', 'goal_versions.value', 'goal_versions.threshold', 'goal_versions.snapshot_id']);
        foreach ($rows as $row) {
            $goals[$row->website_id][] = $row;
        }

        return $goals;
    }

    private function version(int $goal, int $version): ?object
    {
        return DB::table('goal_versions')->join('goals', 'goals.id', '=', 'goal_versions.goal_id')->where('goal_versions.goal_id', $goal)->where('goal_versions.version', $version)->whereNull('goals.archived_at')
            ->first(['goals.website_id', 'goals.id as goal_id', 'goal_versions.version', 'goal_versions.type', 'goal_versions.value', 'goal_versions.threshold', 'goal_versions.snapshot_id']);
    }

    /**
     * The first event of every session of these rows: from the segments when known, else the earliest pageview.
     *
     * @param  Collection<int, object>  $rows
     * @return array<string, object>
     */
    private function firstEvents(Collection $rows): array
    {
        $firsts = [];
        $sites = $rows->pluck('website_id')->unique()->values();
        foreach ($rows->pluck('session_key')->filter()->unique()->chunk(500) as $keys) {
            foreach (DB::table('segment_sessions')->whereIn('website_id', $sites)->whereIn('session_key', $keys->values())->get() as $row) {
                $firsts[$row->website_id.'|'.$row->session_key] = (object) json_decode($row->event_data, true, flags: JSON_THROW_ON_ERROR);
            }
            $missing = $keys->reject(fn ($key) => $rows->contains(fn ($event) => isset($firsts[$event->website_id.'|'.$key])))->values();
            if ($missing->isEmpty()) {
                continue;
            }
            $ids = DB::table('events')->whereIn('website_id', $sites)->whereIn('session_key', $missing)->where('event_type', 1)->groupBy('website_id', 'session_key')->selectRaw('MIN(id) AS id')->pluck('id');
            foreach (DB::table('events')->whereIn('id', $ids)->get() as $event) {
                $firsts[$event->website_id.'|'.$event->session_key] ??= $event;
            }
        }

        return $firsts;
    }

    private function matches(object $event, object $goal): bool
    {
        return $goal->type === 'event' ? $event->event_type === 2 && $event->event_name === $goal->value : $event->event_type === 1 && $event->path === $goal->value;
    }

    private function ms(string $time): int
    {
        return Carbon::parse($time, 'UTC')->getTimestampMs();
    }

    /**
     * @param  list<array{0: object, 1: object}>  $items  Events with their site.
     * @param  array<int, list<object>>  $goals  Goal versions by site.
     * @param  Closure(int, string): ?object  $first  Reads the first event of a measured session.
     * @param  bool  $live  Whether events up to a version's snapshot are left to its backfill.
     */
    private function process(array $items, array $goals, Closure $first, bool $live): void
    {
        $keys = [];
        $hits = [];
        $touched = [];
        $deltas = [];
        foreach ($items as [$event, $site]) {
            foreach ($goals[$site->id] ?? [] as $goal) {
                $counts = $this->matches($event, $goal) && (! $live || $event->id > $goal->snapshot_id);
                if (! $event->session_key) {
                    // Without a measured session there is no conversion, only a hit.
                    if ($counts) {
                        $this->delta($deltas, $site, $goal, $event->received_at, $event, 0, 1, $event);
                    }

                    continue;
                }
                $slot = implode('|', [$site->id, $goal->goal_id, $goal->version, $event->session_key]);
                $keys[$event->session_key] = true;
                $touched[$slot] = [$site, $goal, $event->session_key];
                if ($counts) {
                    $hits[$slot][] = $event;
                }
            }
        }
        $rows = [];
        $goalIds = array_values(array_unique(array_map(fn (array $t): int => $t[1]->goal_id, $touched)));
        $siteIds = array_values(array_unique(array_map(fn (array $t): int => $t[0]->id, $touched)));
        foreach (array_chunk(array_keys($keys), 500) as $chunk) {
            foreach (DB::table('goal_sessions')->whereIn('website_id', $siteIds)->whereIn('goal_id', $goalIds)->whereIn('session_key', $chunk)->get() as $row) {
                $rows[implode('|', [$row->website_id, $row->goal_id, $row->version, $row->session_key])] = $row;
            }
        }
        $writes = [];
        foreach ($touched as $slot => [$site, $goal, $key]) {
            $row = $rows[$slot] ?? null;
            $mine = $hits[$slot] ?? [];
            if (! $row && ! $mine) {
                continue;
            }
            usort($mine, fn (object $a, object $b): int => [$this->ms($a->received_at), $a->id] <=> [$this->ms($b->received_at), $b->id]);
            $firstEvent = $first($site->id, $key) ?? ($mine[0] ?? null);
            if (! $firstEvent) {
                continue;
            }
            $count = (int) ($row->hits ?? 0);
            $convertedAt = $row->converted_at ?? null;
            $before = $convertedAt !== null ? [$convertedAt, (object) json_decode($row->event_data, true, flags: JSON_THROW_ON_ERROR)] : null;
            foreach ($mine as $event) {
                $count++;
                $this->delta($deltas, $site, $goal, $event->received_at, $firstEvent, 0, 1, $event);
                if ($convertedAt === null) {
                    $convertedAt = $count >= (int) $goal->threshold ? $event->received_at : null;
                } elseif ((int) $goal->threshold === 1 && $this->ms($event->received_at) < $this->ms($convertedAt)) {
                    $convertedAt = $event->received_at;
                }
            }
            $lastHit = $row->last_hit_at ?? null;
            foreach ($mine as $event) {
                if ($lastHit === null || $this->ms($event->received_at) > $this->ms($lastHit)) {
                    $lastHit = $event->received_at;
                }
            }
            $data = Segments::normalise($row->event_data ?? null);
            if ($convertedAt !== null) {
                $data = Segments::snapshot($firstEvent);
                if ($before === null) {
                    $this->delta($deltas, $site, $goal, $convertedAt, $firstEvent, 1, 0);
                } elseif ($this->place($site, $before[0], $before[1]) !== $this->place($site, $convertedAt, $firstEvent)) {
                    $this->delta($deltas, $site, $goal, $before[0], $before[1], -1, 0);
                    $this->delta($deltas, $site, $goal, $convertedAt, $firstEvent, 1, 0);
                }
            }
            $prior = $row?->converted_at;
            $moved = ($convertedAt === null) !== ($prior === null) || ($convertedAt !== null && $this->ms($convertedAt) !== $this->ms($prior));
            if (! $row || $count !== (int) $row->hits || $moved || $data !== Segments::normalise($row->event_data)) {
                $writes[] = ['website_id' => $site->id, 'goal_id' => $goal->goal_id, 'version' => $goal->version, 'session_key' => $key, 'hits' => $count, 'converted_at' => $convertedAt, 'last_hit_at' => $lastHit, 'event_data' => $data];
            }
        }
        foreach (array_chunk($writes, 200) as $chunk) {
            DB::table('goal_sessions')->upsert($chunk, self::SESSION_KEY, ['hits', 'converted_at', 'last_hit_at', 'event_data']);
        }
        if ($live) {
            foreach ($deltas as $row) {
                $key = $row['website_id'].'|'.$row['goal_id'].'|'.$row['version'];
                $this->converted[$key] ??= ['site' => (int) $row['website_id'], 'goal' => (int) $row['goal_id'], 'version' => (int) $row['version'], 'count' => 0];
                $this->converted[$key]['count'] += $row['conversions'];
            }
        }
        $this->flushDeltas($deltas);
    }

    /**
     * Where a conversion is counted: its local day and the segment of the session's first event.
     */
    private function place(object $site, string $at, object $first): string
    {
        return Carbon::parse($at, 'UTC')->setTimezone($site->timezone)->toDateString().'|'.json_encode(app(Filters::class)->values($first), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, array<string, mixed>>  $deltas
     */
    private function delta(array &$deltas, object $site, object $goal, string $at, object $first, int $conversions, int $hits, ?object $hit = null): void
    {
        $dimensions = app(Filters::class)->values($first);
        $json = json_encode($dimensions, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $local = Carbon::parse($at, 'UTC')->setTimezone($site->timezone);
        $identity = ['website_id' => $site->id, 'goal_id' => $goal->goal_id, 'version' => $goal->version, 'bucket_start' => $local->copy()->startOfDay()->utc()->toDateTimeString(), 'segment_hash' => hash('sha256', $json)];
        $key = implode('|', $identity);
        $deltas[$key] ??= $identity + $dimensions + ['stat_date' => $local->toDateString(), 'dimensions' => $json, 'conversions' => 0, 'hits' => 0, 'value_sum' => 0, 'valued_hits' => 0];
        $deltas[$key]['conversions'] += $conversions;
        $deltas[$key]['hits'] += $hits;
        // The value of a hit follows the hit itself, so it lands where the hit lands (the first touch of its session).
        if ($hit !== null && ($hit->event_value ?? null) !== null) {
            $deltas[$key]['value_sum'] += (int) $hit->event_value;
            $deltas[$key]['valued_hits']++;
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $deltas
     */
    private function flushDeltas(array $deltas): void
    {
        $rows = array_values(array_filter($deltas, fn (array $row): bool => $row['conversions'] !== 0 || $row['hits'] !== 0 || $row['value_sum'] !== 0 || $row['valued_hits'] !== 0));
        $expressions = UpsertIncrement::columns(['conversions', 'hits', 'value_sum', 'valued_hits']);
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('stats_goal_daily')->upsert($chunk, ['website_id', 'goal_id', 'version', 'bucket_start', 'segment_hash'], $expressions);
        }
    }
}
