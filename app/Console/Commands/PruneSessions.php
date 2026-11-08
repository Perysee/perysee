<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Analytics\InstanceHealth;
use App\Analytics\Maintenance\AggregationLock;
use App\Analytics\Maintenance\RetentionPurge;
use Illuminate\Console\Command;

/**
 * Retention of the session registries (ADR 010): measured_sessions and segment_sessions are kept as long as the
 * statistics, plus a day of margin, and only while no unprocessed event older than that is waiting. It runs on
 * its own schedule so that the idle maintenance pass costs no query more.
 */
class PruneSessions extends Command
{
    /** Batches per run and per table: a long backlog is worked off over several runs instead of holding the scheduler. */
    private const MAX_BATCHES = 20;

    protected $signature = 'perysee:prune-sessions {--limit=1000 : Maximum rows deleted per batch and per table}';

    protected $description = 'Delete session registry rows older than the statistics retention, in bounded batches';

    public function handle(InstanceHealth $health, RetentionPurge $purge): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);
        if ($limit === false) {
            $this->error('Use a limit between 1 and 10000.');

            return self::FAILURE;
        }
        $cutoff = now('UTC')->subDays($health->effectiveRetentionDays('stats_retention_days'));
        // The usual hourly pass: nothing is old enough, and this single read is the whole cost.
        if (! $purge->pending('sessions', $cutoff)) {
            return self::SUCCESS;
        }
        $lock = new AggregationLock;
        if (! $lock->acquire()) {
            $this->info('Aggregation is active; session purge will resume next time.');

            return self::SUCCESS;
        }
        try {
            if ($purge->blocked('sessions', $cutoff)) {
                $this->info('Events older than the retention are still waiting to be consolidated; sessions are kept for now.');

                return self::SUCCESS;
            }
            $deleted = $purge->purge('sessions', $cutoff, $limit, self::MAX_BATCHES);
            $this->info('Removed '.($deleted['measured_sessions'] ?? 0).' measured sessions and '.($deleted['segment_sessions'] ?? 0).' segment sessions.');

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
