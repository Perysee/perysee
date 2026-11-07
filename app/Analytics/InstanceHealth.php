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
     * A silent scheduler explains the waiting events, so only it is reported then.
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

        return $waitingMinutes >= 15
            ? [['code' => 'backlog', 'message' => "Events are waiting to be consolidated, the oldest for {$waitingMinutes} minutes. The latest figures may be missing."]]
            : [];
    }

    public function snapshot(): array
    {
        $pending = DB::table('events')->whereNull('processed_at');
        $size = null;
        if (DB::getDriverName() === 'mariadb' || DB::getDriverName() === 'mysql') {
            $size = (int) DB::selectOne('SELECT SUM(data_length + index_length) AS bytes FROM information_schema.tables WHERE table_schema = DATABASE()')->bytes;
        }

        return [
            'journey_reports_pending' => DB::table('journey_reports')->whereIn('status', ['scanning', 'reducing'])->count(),
            'journey_reports_errors' => DB::table('journey_reports')->where('status', 'error')->count(),
            'segments_rebuild' => $this->get('segments_rebuild'),
            'geolocation' => app(Geolocation::class)->status(),
            'heartbeat' => $this->get('heartbeat'), 'aggregate_success' => $this->get('aggregate_success'),
            'aggregate_error' => $this->get('aggregate_error'), 'backlog' => (clone $pending)->count(),
            'oldest_pending' => (clone $pending)->min('received_at'), 'database_bytes' => $size,
            'collection_failures' => app(Coordination::class)->failures('collection_failures'),
            'collection_rejections' => app(Coordination::class)->failures('collection_rejections'),
            'raw_retention_days' => (int) $this->get('raw_retention_days', config('perysee.raw_retention_days', 90)),
            'stats_retention_days' => (int) $this->get('stats_retention_days', 1095),
            'deleting_sites' => DB::table('websites')->whereNotNull('deleting_at')->count(),
            'checked_at' => now('UTC')->toIso8601String(),
        ];
    }
}
