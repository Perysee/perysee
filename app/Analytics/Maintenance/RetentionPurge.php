<?php

declare(strict_types=1);

namespace App\Analytics\Maintenance;

use App\Analytics\Aggregation\Segments;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What is deleted once data is older than a cutoff, in one place: the scheduled commands and the operator's
 * `perysee:purge` use the same rules, so a manual purge can never delete what the hourly ones would refuse to.
 *
 * Every target works in bounded batches (one SELECT of keys, one delete per batch of keys) and never deletes an
 * event that has not been consolidated. The aggregates, the session registries and the segment counters are also
 * kept while an unprocessed event older than the cutoff exists: its consolidation can still move counters of those
 * historical days and needs the registries to recount a session (Segments::ingest, Aggregator).
 *
 * The caller holds the aggregation lock ({@see AggregationLock}).
 */
final class RetentionPurge
{
    /** The tables of each target, in the order they are emptied. */
    public const TARGETS = [
        'events' => ['events', 'goal_sessions'],
        'stats' => ['stats_daily', 'stats_hourly', 'stats_dimension_daily', 'stats_goal_daily'],
        'segments' => ['stats_segment_daily', 'stats_segment_hourly'],
        'sessions' => ['measured_sessions', 'segment_sessions'],
        'share-links' => ['share_links'],
    ];

    /** The hourly aggregates, which the storage tiers can keep for less time than the daily ones (ADR 011). */
    public const HOURLY = ['stats_hourly', 'stats_segment_hourly'];

    /** The tables that follow the pending events guard: the aggregates and registries a late event can still move. */
    private const GUARDED = ['stats_daily', 'stats_hourly', 'stats_dimension_daily', 'stats_goal_daily', 'stats_segment_daily', 'stats_segment_hourly', 'measured_sessions', 'segment_sessions'];

    /** The column rows are taken by, oldest first: indexed, so that a batch never scans the table. */
    private const AGE = [
        'events' => 'received_at', 'goal_sessions' => 'last_hit_at', 'measured_sessions' => 'last_seen_at',
        'segment_sessions' => 'first_seen_at', 'share_links' => 'id',
    ];

    /**
     * Whether a cutoff has anything to delete, in one read.
     *
     * @param  string|list<string>  $target
     */
    public function pending(string|array $target, Carbon $cutoff): bool
    {
        $union = null;
        foreach (self::tables($target) as $table) {
            $query = $this->expired($table, $cutoff)->selectRaw('1 AS found')->limit(1);
            $union = $union === null ? $query : $union->unionAll($query);
        }

        return $union !== null && DB::query()->fromSub($union, 'due')->exists();
    }

    /**
     * Whether a target is kept for now because an event still waiting for consolidation is older than the cutoff.
     *
     * @param  string|list<string>  $target
     */
    public function blocked(string|array $target, Carbon $cutoff): bool
    {
        return array_intersect(self::tables($target), self::GUARDED) !== [] && $this->statsLocked($cutoff);
    }

    /**
     * The tables of a target, or the list itself when the caller names tables (the maintenance pass empties the
     * statistics and the segment counters together, behind one guard).
     *
     * @param  string|list<string>  $target
     * @return list<string>
     */
    public static function tables(string|array $target): array
    {
        return is_array($target) ? $target : self::TARGETS[$target];
    }

    /**
     * Rows each table would lose: an exact count, for a simulation. Nothing is deleted.
     *
     * @return array<string, int>
     */
    public function count(string $target, Carbon $cutoff): array
    {
        $counts = [];
        foreach (self::tables($target) as $table) {
            $counts[$table] = $this->expired($table, $cutoff)->count();
        }

        return $counts;
    }

    /**
     * Deletes what is older than the cutoff, at most $maxBatches batches of $limit rows per table.
     *
     * @param  string|list<string>  $target
     * @return array<string, int> Rows deleted by table; empty when the guard holds the target back.
     */
    public function purge(string|array $target, Carbon $cutoff, int $limit, int $maxBatches): array
    {
        if ($this->blocked($target, $cutoff)) {
            return [];
        }
        $deleted = [];
        foreach (self::tables($target) as $table) {
            $deleted[$table] = 0;
            for ($batch = 0; $batch < $maxBatches; $batch++) {
                $removed = $table === 'events' ? $this->eventsBatch($cutoff, $limit) : $this->tableBatch($table, $cutoff, $limit);
                $deleted[$table] += $removed;
                if ($removed === 0) {
                    break;
                }
            }
        }

        return $deleted;
    }

    /** The first day (UTC) whose statistics are kept: a day of margin preserves every site's local retention day. */
    public static function statsDate(Carbon $cutoff): string
    {
        return $cutoff->copy()->utc()->subDay()->toDateString();
    }

    private function statsLocked(Carbon $cutoff): bool
    {
        return DB::table('events')->whereNull('processed_at')->where('received_at', '<', self::statsDate($cutoff))->exists();
    }

    /** The rows of a table that are older than the cutoff. */
    private function expired(string $table, Carbon $cutoff): Builder
    {
        $date = self::statsDate($cutoff);
        $query = DB::table($table);

        return match ($table) {
            'events' => $query->whereNotNull('processed_at')->where('received_at', '<', $cutoff),
            'goal_sessions' => $query->where('last_hit_at', '<', $cutoff),
            'measured_sessions' => $query->where('last_seen_at', '<', $date),
            'segment_sessions' => $query->where('first_seen_at', '<', $date),
            'share_links' => $query->where(fn ($query) => $query->where('expires_at', '<', $cutoff)->orWhere('revoked_at', '<', $cutoff)),
            default => $query->where('stat_date', '<', $date),
        };
    }

    /** One batch of the tables that are identified by their keys. */
    private function tableBatch(string $table, Carbon $cutoff, int $limit): int
    {
        $keys = $table === 'share_links' ? ['id'] : ['website_id', ...SiteTables::KEYS[$table]];
        $rows = $this->expired($table, $cutoff)->orderBy(self::AGE[$table] ?? 'stat_date')->limit($limit)->get($keys);
        if ($rows->isEmpty()) {
            return 0;
        }
        KeyedDelete::rows($table, $keys, $rows);

        return $rows->count();
    }

    /**
     * One batch of consolidated events. Their segment counters are completed first when the ledger does not know
     * them yet, so that a pending rebuild loses nothing (see Segments).
     */
    private function eventsBatch(Carbon $cutoff, int $limit): int
    {
        $ids = $this->expired('events', $cutoff)->orderBy('received_at')->orderBy('id')->limit($limit)->pluck('id');
        if ($ids->isEmpty()) {
            return 0;
        }

        return DB::transaction(function () use ($ids): int {
            $events = DB::table('events')->whereIn('id', $ids)->lockForUpdate()->get();
            $sites = DB::table('websites')->whereIn('id', $events->pluck('website_id')->unique())->whereNull('deleting_at')->get()->keyBy('id');
            $segments = app(Segments::class);
            $segments->begin($events);
            foreach ($events as $event) {
                if ($site = $sites->get($event->website_id)) {
                    $segments->ingest($event, $site);
                }
            }
            $segments->flush();
            DB::table('segment_events')->whereIn('event_id', $ids)->delete();

            return DB::table('events')->whereIn('id', $ids)->delete();
        });
    }
}
