<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\AccessLog;
use App\Support\UserSessions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneAccessLog extends Command
{
    /** Batches per run: a long backlog is worked off over several runs instead of holding the scheduler. */
    private const MAX_BATCHES = 20;

    protected $signature = 'perysee:prune-access-log {--limit=1000 : Maximum rows deleted per batch and per table}';

    protected $description = 'Delete access log entries older than 90 days and open-session rows nobody renewed';

    public function handle(UserSessions $sessions): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);
        if ($limit === false) {
            $this->error('Use a limit between 1 and 10000.');

            return self::FAILURE;
        }
        $cutoff = now('UTC')->subDays(AccessLog::RETENTION_DAYS);
        $entries = $expired = 0;
        for ($batch = 0; $batch < self::MAX_BATCHES; $batch++) {
            $ids = DB::table('access_log')->where('created_at', '<', $cutoff)->orderBy('id')->limit($limit)->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }
            $entries += DB::table('access_log')->whereIn('id', $ids)->delete();
        }
        for ($batch = 0; $batch < self::MAX_BATCHES; $batch++) {
            $removed = $sessions->purgeExpired($limit);
            $expired += $removed;
            if ($removed === 0) {
                break;
            }
        }
        $this->info("Removed {$entries} access log entries and {$expired} expired session rows.");

        return self::SUCCESS;
    }
}
