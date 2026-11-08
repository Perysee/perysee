<?php

namespace App\Console\Commands;

use App\Analytics\InstanceHealth;
use App\Analytics\Maintenance\KeyedDelete;
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
            $days = (int) $health->get('stats_retention_days', 1095);
            // Conservative UTC cutoff keeps every site's current local retention day intact.
            $cutoff = now('UTC')->subDays($days + 1)->toDateString();
            // Pending work can still move session counters in historical buckets.
            $statsLocked = DB::table('events')->whereNull('processed_at')->where('received_at', '<', $cutoff)->exists();
            foreach ($statsLocked ? [] : ['stats_daily', 'stats_hourly', 'stats_dimension_daily', 'stats_segment_daily', 'stats_segment_hourly', 'stats_goal_daily'] as $table) {
                $keys = match ($table) {
                    'stats_segment_daily', 'stats_segment_hourly' => ['website_id', 'bucket_start', 'segment_hash'],
                    'stats_goal_daily' => ['website_id', 'goal_id', 'version', 'bucket_start', 'segment_hash'],
                    'stats_daily' => ['website_id', 'stat_date'], 'stats_hourly' => ['website_id', 'bucket_start'],
                    default => ['website_id', 'stat_date', 'dimension_type', 'dimension_hash'],
                };
                $expired = DB::table($table)->where('stat_date', '<', $cutoff)->orderBy('stat_date');
                $rows = $expired->limit($limit)->get($keys);
                if ($rows->isNotEmpty()) {
                    KeyedDelete::rows($table, $keys, $rows);
                }
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
