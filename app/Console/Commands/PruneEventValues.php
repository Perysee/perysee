<?php

namespace App\Console\Commands;

use App\Analytics\InstanceHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Retention of what the event values feature keeps: the remembered order identifiers, and the daily property
 * counters. It runs on its own schedule so that the idle maintenance pass costs no query more.
 */
class PruneEventValues extends Command
{
    protected $signature = 'perysee:prune-event-values {--limit=1000 : Maximum rows to delete per table}';

    protected $description = 'Delete expired order identifiers and event property statistics, one bounded batch at a time';

    public function handle(InstanceHealth $health): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);
        if ($limit === false) {
            $this->error('Use a limit between 1 and 10000.');

            return self::FAILURE;
        }
        $window = max(1, (int) config('perysee.order_dedup_days', 30));
        $orders = DB::table('event_order_ids')->where('created_at', '<', now('UTC')->subDays($window))->orderBy('created_at')->limit($limit)->get(['website_id', 'order_hash']);
        foreach ($orders->groupBy('website_id') as $site => $rows) {
            DB::table('event_order_ids')->where('website_id', $site)->whereIn('order_hash', $rows->pluck('order_hash'))->delete();
        }

        // Same cutoff and same guard as the other statistics: a pending event can still land in an old day.
        $cutoff = now('UTC')->subDays((int) $health->get('stats_retention_days', 1095) + 1)->toDateString();
        $days = 0;
        if (! DB::table('events')->whereNull('processed_at')->where('received_at', '<', $cutoff)->exists()) {
            foreach (DB::table('stats_event_property_daily')->where('stat_date', '<', $cutoff)->select('website_id', 'stat_date')->distinct()->orderBy('stat_date')->limit(max(1, intdiv($limit, 20)))->get() as $day) {
                DB::table('stats_event_property_daily')->where('website_id', $day->website_id)->where('stat_date', $day->stat_date)->delete();
                $days++;
            }
        }
        $this->info("Deleted {$orders->count()} order identifiers and {$days} days of event properties.");

        return self::SUCCESS;
    }
}
