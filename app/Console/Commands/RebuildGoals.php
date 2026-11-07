<?php

namespace App\Console\Commands;

use App\Analytics\Aggregation\Goals;
use Illuminate\Console\Command;

class RebuildGoals extends Command
{
    protected $signature = 'perysee:rebuild-goals {--batch=500}';

    protected $description = 'Count a bounded batch of past events for goals defined after the fact';

    public function handle(Goals $goals): int
    {
        $batch = filter_var($this->option('batch'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        if ($batch === false) {
            $this->error('Batch must be between 1 and 1000.');

            return self::FAILURE;
        }
        $this->info('Counted '.$goals->rebuild($batch).' events for goals.');

        return self::SUCCESS;
    }
}
