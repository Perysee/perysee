<?php

namespace App\Console\Commands;

use App\Analytics\InstanceHealth;
use App\Analytics\Maintenance\AggregationLock;
use App\Analytics\Maintenance\RetentionPurge;
use Illuminate\Console\Command;

class PruneTracking extends Command
{
    protected $signature = 'perysee:prune-tracking {--limit=1000 : Maximum expired rows to delete}';

    protected $description = 'Delete one bounded batch of tracking events older than the configured retention';

    public function handle(): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);
        $days = app(InstanceHealth::class)->effectiveRetentionDays('raw_retention_days');
        if ($limit === false || $days < 1) {
            $this->error('Use a limit between 1 and 10000 and a retention of at least one day.');

            return self::FAILURE;
        }
        $lock = new AggregationLock;
        if (! $lock->acquire()) {
            return self::SUCCESS;
        }
        try {
            // One batch per pass: the rule is shared with the hourly purges and the operator's `perysee:purge`.
            // The goal ledger only deduplicates live sessions; the aggregated goal counters follow the statistics retention like the other aggregates.
            $deleted = app(RetentionPurge::class)->purge('events', now('UTC')->subDays($days), $limit, 1, noteExpiry: true)['events'] ?? 0;
            $this->info("Deleted {$deleted} expired tracking events.");

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
