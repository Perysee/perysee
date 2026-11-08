<?php

namespace App\Analytics;

use App\Analytics\Ingestion\Geolocation;
use App\Support\Coordination;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class InstanceHealth
{
    public function put(string $name, mixed $value): void
    {
        DB::table('analytics_state')->upsert([['name' => $name, 'value' => json_encode($value, JSON_THROW_ON_ERROR), 'updated_at' => now('UTC')]], ['name'], ['value', 'updated_at']);
    }

    public function get(string $name, mixed $default = null): mixed
    {
        $value = DB::table('analytics_state')->where('name', $name)->value('value');

        return $value === null ? $default : json_decode($value, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Several states in one read; a name nobody set is absent from the result.
     *
     * @param  list<string>  $names
     * @return array<string, mixed>
     */
    public function many(array $names): array
    {
        return DB::table('analytics_state')->whereIn('name', $names)->pluck('value', 'name')->map(fn (string $value): mixed => json_decode($value, true, flags: JSON_THROW_ON_ERROR))->all();
    }

    /** The retention the administrator chose for `raw_retention_days` or `stats_retention_days`, its default when nobody set it. */
    public function retentionDays(string $name): int
    {
        return (int) $this->get($name, $name === 'raw_retention_days' ? config('perysee.raw_retention_days', 90) : 1095);
    }

    /**
     * The retention the purges apply: the administrator's settings capped by the storage tier in force (ADR 011).
     * This is the only place that combines them, in one read, and it never exceeds a setting.
     *
     * @return array{raw: int, stats: int, hourly: int, tier: int}
     */
    public function effectiveRetention(): array
    {
        $state = $this->many(['raw_retention_days', 'stats_retention_days', 'storage_tier']);
        $raw = (int) ($state['raw_retention_days'] ?? config('perysee.raw_retention_days', 90));
        $stats = (int) ($state['stats_retention_days'] ?? 1095);
        $tier = max(0, min(StorageTiers::MAX, (int) ($state['storage_tier'] ?? 0)));

        return ['raw' => StorageTiers::rawDays($raw, $tier), 'stats' => $stats, 'hourly' => StorageTiers::hourlyDays($stats, $tier), 'tier' => $tier];
    }

    /** The retention in force, tier included, for `raw_retention_days` or `stats_retention_days`. */
    public function effectiveRetentionDays(string $name): int
    {
        return $this->effectiveRetention()[$name === 'raw_retention_days' ? 'raw' : 'stats'];
    }

    public function forget(string $name): void
    {
        DB::table('analytics_state')->where('name', $name)->delete();
    }

    public function failure(string $name): void
    {
        try {
            app(Coordination::class)->failure($name);
        } catch (\Throwable) {
            // A diagnostic must never replace the original collection failure.
        }
    }

    /**
     * What an administrator should look at first, cheap enough to run on the home page. It mirrors the two
     * warnings of the Instance health page: a scheduler silent for ten minutes, and events waiting for fifteen.
     * A silent scheduler explains the waiting events, so only it is reported then. The storage quota is announced
     * when the projection puts it less than thirty days away, and the storage budget when the database reaches it.
     *
     * @return list<array{code: string, message: string}>
     */
    public function attention(): array
    {
        $heartbeat = $this->get('heartbeat');
        $silentMinutes = $heartbeat === null ? null : intdiv(max(0, now('UTC')->timestamp - Carbon::parse($heartbeat)->timestamp), 60);
        if ($silentMinutes === null || $silentMinutes >= 10) {
            return [['code' => 'scheduler', 'message' => $silentMinutes === null
                ? 'The scheduler has never run on this instance. Reports, funnels and journeys will not update until it does.'
                : "The scheduler has not run for {$silentMinutes} minutes. Reports, funnels and journeys will not update until it does."]];
        }
        $oldest = DB::table('events')->whereNull('processed_at')->min('received_at');
        $waitingMinutes = $oldest === null ? 0 : intdiv(max(0, now('UTC')->timestamp - Carbon::parse($oldest, 'UTC')->timestamp), 60);

        $attention = $waitingMinutes >= 15
            ? [['code' => 'backlog', 'message' => "Events are waiting to be consolidated, the oldest for {$waitingMinutes} minutes. The latest figures may be missing."]]
            : [];
        // Reads the snapshots only once an administrator has entered a quota.
        $storage = app(StorageProjection::class)->alert();
        // The budget alert reads the last hourly measurement, never the engine's statistics.
        $budget = app(StorageBudget::class)->alert();

        return [...$attention, ...($storage === null ? [] : [$storage]), ...($budget === null ? [] : [$budget])];
    }

    public function snapshot(): array
    {
        $pending = DB::table('events')->whereNull('processed_at');
        $tables = app(StorageMeter::class)->tables();
        $projection = app(StorageProjection::class)->forecast();

        return [
            'journey_reports_pending' => DB::table('journey_reports')->whereIn('status', ['scanning', 'reducing'])->count(),
            'journey_reports_errors' => DB::table('journey_reports')->where('status', 'error')->count(),
            'segments_rebuild' => $this->get('segments_rebuild'),
            'geolocation' => app(Geolocation::class)->status(),
            'heartbeat' => $this->get('heartbeat'), 'aggregate_success' => $this->get('aggregate_success'),
            'aggregate_error' => $this->get('aggregate_error'), 'backlog' => (clone $pending)->count(),
            'oldest_pending' => (clone $pending)->min('received_at'), 'database_bytes' => $tables === null ? null : StorageMeter::total($tables),
            'largest_tables' => array_slice($tables ?? [], 0, 8),
            'storage_quota_bytes' => $projection['quota_bytes'],
            'storage_projection' => $projection,
            'storage_budget' => app(StorageBudget::class)->status($tables === null ? null : StorageMeter::total($tables)),
            'collection_failures' => app(Coordination::class)->failures('collection_failures'),
            'collection_rejections' => app(Coordination::class)->failures('collection_rejections'),
            'property_truncations' => app(Coordination::class)->failures('property_truncations'),
            'raw_retention_days' => $this->retentionDays('raw_retention_days'),
            'stats_retention_days' => $this->retentionDays('stats_retention_days'),
            'deleting_sites' => DB::table('websites')->whereNotNull('deleting_at')->count(),
            'checked_at' => now('UTC')->toIso8601String(),
        ];
    }
}
