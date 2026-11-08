<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Analytics\StorageBudget;
use Illuminate\Console\Command;

/**
 * The hourly pass of the storage budget (ADR 011). With no budget entered it costs one read; with one it measures
 * the database once (the engine's statistics), records the figure for the Health page and moves the retention tier
 * when the mode and the figure call for it. It deletes nothing itself: the purges read the effective retention.
 */
class EvaluateStorageBudget extends Command
{
    protected $signature = 'perysee:storage-budget';

    protected $description = 'Compare the database size with the storage budget and move the adaptive retention tier';

    public function handle(StorageBudget $budget): int
    {
        $budget->run();

        return self::SUCCESS;
    }
}
