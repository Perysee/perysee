<?php

namespace App\Analytics;

use App\Analytics\Ingestion\Geolocation;
use Illuminate\Support\Facades\Cache;
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

    public function failure(string $name): void
    {
        try {
            Cache::add('analytics:'.$name, 0, now()->addDays(7));
            Cache::increment('analytics:'.$name);
        } catch (\Throwable) {
            // A diagnostic must never replace the original collection failure.
        }
    }

    public function snapshot(): array
    {
        $pending = DB::table('events')->whereNull('processed_at');
        $size = null;
        if (DB::getDriverName() === 'mariadb' || DB::getDriverName() === 'mysql') {
            $size = (int) DB::selectOne('SELECT SUM(data_length + index_length) AS bytes FROM information_schema.tables WHERE table_schema = DATABASE()')->bytes;
        }

        return [
            'geolocation' => app(Geolocation::class)->status(),
            'heartbeat' => $this->get('heartbeat'), 'aggregate_success' => $this->get('aggregate_success'),
            'aggregate_error' => $this->get('aggregate_error'), 'backlog' => (clone $pending)->count(),
            'oldest_pending' => (clone $pending)->min('received_at'), 'database_bytes' => $size,
            'collection_failures' => (int) Cache::get('analytics:collection_failures', 0),
            'collection_rejections' => (int) Cache::get('analytics:collection_rejections', 0),
            'raw_retention_days' => (int) $this->get('raw_retention_days', config('perysee.raw_retention_days', 90)),
            'stats_retention_days' => (int) $this->get('stats_retention_days', 1095),
            'deleting_sites' => DB::table('websites')->whereNotNull('deleting_at')->count(),
            'checked_at' => now('UTC')->toIso8601String(),
        ];
    }
}
