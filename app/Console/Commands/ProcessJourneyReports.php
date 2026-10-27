<?php

namespace App\Console\Commands;

use App\Analytics\Reporting\JourneyReports;
use Illuminate\Console\Command;

class ProcessJourneyReports extends Command
{
    protected $signature = 'perysee:journey-reports {--batch=500} {--budget=10}';

    protected $description = 'Process resumable funnel and journey reports within a time budget';

    public function handle(JourneyReports $reports): int
    {
        $batch = filter_var($this->option('batch'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        $budget = filter_var($this->option('budget'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 30]]);
        if ($batch === false || $budget === false) {
            $this->error('Use batch 1–1000 and budget 1–30 seconds.');

            return self::FAILURE;
        }
        $deadline = microtime(true) + $budget;
        while (microtime(true) < $deadline && $reports->tick($batch)) {
        }

        return self::SUCCESS;
    }
}
