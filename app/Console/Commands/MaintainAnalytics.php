<?php

namespace App\Console\Commands;

use App\Analytics\InstanceHealth;
use App\Analytics\Maintenance\KeyedDelete;
use App\Analytics\Maintenance\RetentionPurge;
use App\Analytics\Maintenance\SiteTables;
use App\Modules\Events\SchedulerWatch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MaintainAnalytics extends Command
{
    protected $signature = 'perysee:maintenance {--limit=1000 : Maximum rows per maintenance category}';

    protected $description = 'Record scheduler heartbeat and perform bounded, resumable data maintenance';

    public function handle(InstanceHealth $health): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);
        if ($limit === false) {
            $this->error('Use a limit between 1 and 10000.');

            return self::FAILURE;
        }
        // Records the heartbeat; a module listening to scheduler.heartbeat_missed also learns of a recovery (no extra query otherwise).
        app(SchedulerWatch::class)->beat($health);
        $maria = in_array(DB::getDriverName(), ['mariadb', 'mysql'], true);
        $name = 'perysee:aggregate:'.substr(hash('sha256', DB::connection()->getDatabaseName()), 0, 32);
        if ($maria && (int) DB::selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$name])->acquired !== 1) {
            $this->info('Aggregation is active; maintenance will resume next time.');

            return self::SUCCESS;
        }
        try {
            $site = DB::table('websites')->whereNotNull('deleting_at')->orderBy('id')->first();
            if ($site) {
                $remaining = $limit;
                foreach (SiteTables::KEYS as $table => $keys) {
                    $query = DB::table($table)->where('website_id', $site->id);
                    foreach ($keys as $key) {
                        $query->orderBy($key);
                    }
                    $rows = $query->limit(max(1, $remaining))->get($keys);
                    if ($rows->isEmpty()) {
                        continue;
                    }
                    if ($remaining === 0) {
                        return self::SUCCESS;
                    }
                    KeyedDelete::rows($table, $keys, $rows, $site->id);
                    $remaining -= $rows->count();
                }
                DB::transaction(function () use ($site): void {
                    DB::table('websites')->where('id', $site->id)->lockForUpdate()->first();
                    DB::table('site_members')->where('website_id', $site->id)->delete();
                    DB::table('analytics_state')->where('name', 'aggregate_site:'.$site->id)->delete();
                    DB::table('websites')->where('id', $site->id)->delete();
                });
            }
            // Conservative UTC cutoff keeps every site's current local retention day intact; pending work can still move
            // session counters in historical buckets, so the purge waits for it (RetentionPurge).
            // The storage tiers (ADR 011) can keep the hourly aggregates for less time than the daily ones.
            $retention = $health->effectiveRetention();
            $purge = app(RetentionPurge::class);
            $aggregates = [...RetentionPurge::tables('stats'), ...RetentionPurge::tables('segments')];
            if ($retention['hourly'] >= $retention['stats']) {
                $purge->purge($aggregates, now('UTC')->subDays($retention['stats']), $limit, 1);
            } else {
                $purge->purge(array_diff($aggregates, RetentionPurge::HOURLY), now('UTC')->subDays($retention['stats']), $limit, 1);
                $purge->purge(RetentionPurge::HOURLY, now('UTC')->subDays($retention['hourly']), $limit, 1);
            }
            $expiredReports = DB::table('journey_reports')->where('created_at', '<', now('UTC')->subDay())->orderBy('id')->limit($limit)->pluck('id');
            if ($expiredReports->isNotEmpty()) {
                $sessions = DB::table('journey_report_sessions')->whereIn('report_id', $expiredReports)->limit($limit)->pluck('id');
                if ($sessions->isNotEmpty()) {
                    DB::table('journey_report_sessions')->whereIn('id', $sessions)->delete();
                }
                // A report goes once its sessions are gone; the limit may leave some for the next pass.
                $withSessions = DB::table('journey_report_sessions')->whereIn('report_id', $expiredReports)->distinct()->pluck('report_id');
                $finished = $expiredReports->diff($withSessions);
                if ($finished->isNotEmpty()) {
                    DB::table('journey_reports')->whereIn('id', $finished)->delete();
                }
            }
            // Invitations that expired, were accepted or were revoked more than 30 days ago are of no use any more.
            $invitationCutoff = now('UTC')->subDays(30);
            $endedInvitations = DB::table('site_invitations')->where(fn ($query) => $query->where('expires_at', '<', $invitationCutoff)->orWhere('accepted_at', '<', $invitationCutoff)->orWhere('revoked_at', '<', $invitationCutoff))->orderBy('id')->limit($limit)->pluck('id');
            // Nothing to purge is the usual case: skip the delete so an idle pass stays within its query budget.
            if ($endedInvitations->isNotEmpty()) {
                DB::table('site_invitations')->whereIn('id', $endedInvitations)->delete();
            }
            $this->call('perysee:prune-tracking', ['--limit' => $limit]);

            return self::SUCCESS;
        } finally {
            if ($maria) {
                DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [$name]);
            }
        }
    }
}
