<?php

namespace App\Console\Commands;

use App\Analytics\Aggregation\Segments;
use Illuminate\Console\Command;

class RebuildSegments extends Command
{
    protected $signature = 'perysee:rebuild-segments {--batch=500}';

    protected $description = 'Rebuild a bounded batch of cross-filter statistics from retained events';

    public function handle(Segments $segments): int
    {
        $batch = filter_var($this->option('batch'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        if ($batch === false) {
            $this->error('Batch must be between 1 and 1000.');

            return self::FAILURE;
        }
        $this->info('Rebuilt '.$segments->rebuild($batch).' events.');

        return self::SUCCESS;
    }
}
