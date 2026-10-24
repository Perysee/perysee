<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneTracking extends Command
{
    protected $signature = 'perysee:prune-tracking {--limit=1000 : Maximum expired rows to delete}';

    protected $description = 'Delete one bounded batch of tracking events older than the configured retention';

    public function handle(): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);
        $days = (int) config('perysee.raw_retention_days');
        if ($limit === false || $days < 1) {
            $this->error('Use a limit between 1 and 10000 and a retention of at least one day.');

            return self::FAILURE;
        }
        $ids = DB::table('events')->where('received_at', '<', now('UTC')->subDays($days))->orderBy('received_at')->orderBy('id')->limit($limit)->pluck('id');
        $deleted = $ids->isEmpty() ? 0 : DB::table('events')->whereIn('id', $ids)->delete();
        $this->info("Deleted {$deleted} expired tracking events.");

        return self::SUCCESS;
    }
}
