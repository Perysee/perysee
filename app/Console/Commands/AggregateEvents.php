<?php

namespace App\Console\Commands;

use App\Analytics\Aggregation\Aggregator;
use Illuminate\Console\Command;

class AggregateEvents extends Command
{
    protected $signature = 'perysee:aggregate {--batch= : Maximum events per transaction} {--budget-seconds= : Execution budget}';

    protected $description = 'Consolidate bounded event batches with transactional replay protection';

    public function handle(Aggregator $aggregator): int
    {
        $batch = $this->option('batch') ?? config('perysee.aggregate.batch', 500);
        $budget = $this->option('budget-seconds') ?? config('perysee.aggregate.budget_seconds', 15);
        if (filter_var($batch, FILTER_VALIDATE_INT) === false || filter_var($budget, FILTER_VALIDATE_INT) === false) {
            $this->error('Batch and budget must be integers.');

            return self::FAILURE;
        }
        try {
            $this->info('Processed '.$aggregator->run((int) $batch, (int) $budget).' events.');

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error('Aggregation failed ('.class_basename($exception).'). No incomplete batch was committed.');

            return self::FAILURE;
        }
    }
}
