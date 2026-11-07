<?php

namespace App\Console\Commands;

use App\Analytics\Ingestion\RejectionLog;
use Illuminate\Console\Command;

class PruneDiagnostics extends Command
{
    protected $signature = 'perysee:prune-diagnostics {--limit=1000 : Maximum expired rows to delete per batch}';

    protected $description = 'Delete collector diagnostics older than seven days, in bounded batches';

    public function handle(RejectionLog $log): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);
        if ($limit === false) {
            $this->error('Use a limit between 1 and 10000.');

            return self::FAILURE;
        }
        // At most ten batches a pass: the next hourly pass resumes whatever is left.
        for ($batch = 0; $batch < 10 && $log->prune($limit) === $limit; $batch++) {
            // Keep deleting while full batches come back.
        }

        return self::SUCCESS;
    }
}
