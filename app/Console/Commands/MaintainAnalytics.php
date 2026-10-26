<?php

namespace App\Console\Commands;

use App\Analytics\InstanceHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MaintainAnalytics extends Command
{
    protected $signature = 'perysee:maintenance {--limit=1000 : Maximum rows per maintenance category}';

    protected $description = 'Record scheduler heartbeat and perform bounded, resumable data maintenance';

    public function handle(InstanceHealth $health): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);
        if ($limit === false) {
            $this->error('Use a limit between 1 and 10000.');

            return self::FAILURE;
        }
        $health->put('heartbeat', now('UTC')->toIso8601String());
        $maria = in_array(DB::getDriverName(), ['mariadb', 'mysql'], true);
        $name = 'perysee:aggregate:'.substr(hash('sha256', DB::connection()->getDatabaseName()), 0, 32);
        if ($maria && (int) DB::selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$name])->acquired !== 1) {
            $this->info('Aggregation is active; maintenance will resume next time.');

            return self::SUCCESS;
        }
        try {
            $site = DB::table('websites')->whereNotNull('deleting_at')->orderBy('id')->first();
            if ($site) {
                $remaining = $limit;
                foreach (['events', 'stats_daily', 'stats_hourly', 'stats_dimension_daily', 'measured_sessions', 'site_measurement_periods'] as $table) {
                    $query = DB::table($table)->where('website_id', $site->id);
                    $keys = match ($table) {
                        'events' => ['id'], 'stats_daily' => ['stat_date'], 'stats_hourly' => ['bucket_start'],
                        'stats_dimension_daily' => ['stat_date', 'dimension_type', 'dimension_hash'], 'measured_sessions' => ['session_key'], 'site_measurement_periods' => ['id'],
                    };
                    foreach ($keys as $key) {
                        $query->orderBy($key);
                    }
                    foreach ($query->limit(max(1, $remaining))->get($keys) as $row) {
                        if ($remaining === 0) {
                            return self::SUCCESS;
                        }
                        DB::table($table)->where('website_id', $site->id)->where((array) $row)->delete();
                        $remaining--;
                    }
                }
                DB::transaction(function () use ($site): void {
                    DB::table('websites')->where('id', $site->id)->lockForUpdate()->first();
                    DB::table('site_members')->where('website_id', $site->id)->delete();
                    DB::table('analytics_state')->where('name', 'aggregate_site:'.$site->id)->delete();
                    DB::table('websites')->where('id', $site->id)->delete();
                });
            }
            $days = (int) $health->get('stats_retention_days', 1095);
            // Conservative UTC cutoff keeps every site's current local retention day intact.
            $cutoff = now('UTC')->subDays($days + 1)->toDateString();
            foreach (['stats_daily', 'stats_hourly', 'stats_dimension_daily'] as $table) {
                // Pending work can still move session counters in historical buckets.
                if (DB::table('events')->whereNull('processed_at')->where('received_at', '<', $cutoff)->exists()) {
                    break;
                }
                $keys = match ($table) {
                    'stats_daily' => ['website_id', 'stat_date'], 'stats_hourly' => ['website_id', 'bucket_start'],
                    default => ['website_id', 'stat_date', 'dimension_type', 'dimension_hash'],
                };
                $expired = DB::table($table)->where('stat_date', '<', $cutoff)->orderBy('stat_date');
                foreach ($expired->limit($limit)->get($keys) as $row) {
                    DB::table($table)->where((array) $row)->delete();
                }
            }
            $this->call('perysee:prune-tracking', ['--limit' => $limit]);

            return self::SUCCESS;
        } finally {
            if ($maria) {
                DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [$name]);
            }
        }
    }
}
