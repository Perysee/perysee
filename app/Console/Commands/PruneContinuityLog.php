<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Analytics\Continuity\ContinuityLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Retention of the continuity log (ADR 009): entries older than 1 095 days, then, per site (and for the instance
 * entries), whatever exceeds 500 rows. It runs on its own schedule so that the idle maintenance pass costs no query more.
 */
class PruneContinuityLog extends Command
{
    /** Batches per run and per step: a long backlog is worked off over several runs instead of holding the scheduler. */
    private const MAX_BATCHES = 20;

    protected $signature = 'perysee:prune-continuity-log {--limit=1000 : Maximum rows deleted per batch}';

    protected $description = 'Delete continuity log entries older than 1095 days and the oldest ones of a site above 500, in bounded batches';

    public function handle(): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);
        if ($limit === false) {
            $this->error('Use a limit between 1 and 10000.');

            return self::FAILURE;
        }
        if (DB::table('continuity_log')->doesntExist()) {
            return self::SUCCESS;
        }
        $cutoff = now('UTC')->subDays(ContinuityLog::RETENTION_DAYS)->format('Y-m-d H:i:s.v');
        $expired = $capped = 0;
        for ($batch = 0; $batch < self::MAX_BATCHES; $batch++) {
            $ids = DB::table('continuity_log')->where('occurred_at', '<', $cutoff)->orderBy('id')->limit($limit)->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }
            $expired += DB::table('continuity_log')->whereIn('id', $ids)->delete();
        }
        for ($batch = 0; $batch < self::MAX_BATCHES; $batch++) {
            $removed = $this->trimOneSite($limit);
            $capped += $removed;
            if ($removed === 0) {
                break;
            }
        }
        $this->info("Removed {$expired} expired and {$capped} surplus continuity log entries.");

        return self::SUCCESS;
    }

    /** Bring the first site above the cap back towards it, and return how many rows went. */
    private function trimOneSite(int $limit): int
    {
        $over = DB::table('continuity_log')->select('website_id', DB::raw('count(*) as entries'))->groupBy('website_id')->havingRaw('count(*) > ?', [ContinuityLog::MAX_PER_SITE])->orderBy('website_id')->first();
        if ($over === null) {
            return 0;
        }
        $site = fn () => DB::table('continuity_log')->when($over->website_id === null, fn ($query) => $query->whereNull('website_id'), fn ($query) => $query->where('website_id', $over->website_id));
        // The last entry of each kind is what still explains the current state of a setting: the cap never takes it.
        $latest = $site()->groupBy('kind')->selectRaw('max(id) as id')->pluck('id');
        $ids = $site()->whereNotIn('id', $latest)->orderByRaw("case when severity = 'note' then 0 else 1 end")->orderBy('id')->limit(min($limit, (int) $over->entries - ContinuityLog::MAX_PER_SITE))->pluck('id');

        return $site()->whereIn('id', $ids)->delete();
    }
}
