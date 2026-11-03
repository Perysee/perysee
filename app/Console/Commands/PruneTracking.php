<?php

namespace App\Console\Commands;

use App\Analytics\Aggregation\Segments;
use App\Analytics\InstanceHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneTracking extends Command
{
    protected $signature = 'perysee:prune-tracking {--limit=1000 : Maximum expired rows to delete}';

    protected $description = 'Delete one bounded batch of tracking events older than the configured retention';

    public function handle(): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);
        $days = (int) app(InstanceHealth::class)->get('raw_retention_days', config('perysee.raw_retention_days', 90));
        if ($limit === false || $days < 1) {
            $this->error('Use a limit between 1 and 10000 and a retention of at least one day.');

            return self::FAILURE;
        }
        $maria = in_array(DB::getDriverName(), ['mariadb', 'mysql'], true);
        $name = 'perysee:aggregate:'.substr(hash('sha256', DB::connection()->getDatabaseName()), 0, 32);
        if ($maria && (int) DB::selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$name])->acquired !== 1) {
            return self::SUCCESS;
        }
        try {
            $ids = DB::table('events')->whereNotNull('processed_at')->where('received_at', '<', now('UTC')->subDays($days))->orderBy('received_at')->orderBy('id')->limit($limit)->pluck('id');
            $deleted = DB::transaction(function () use ($ids): int {
                $events = DB::table('events')->whereIn('id', $ids)->lockForUpdate()->get();
                $sites = DB::table('websites')->whereIn('id', $events->pluck('website_id')->unique())->whereNull('deleting_at')->get()->keyBy('id');
                $segments = app(Segments::class);
                $segments->begin($events);
                foreach ($events as $event) {
                    if ($site = $sites->get($event->website_id)) {
                        $segments->ingest($event, $site);
                    }
                }
                $segments->flush();
                DB::table('segment_events')->whereIn('event_id', $ids)->delete();

                return DB::table('events')->whereIn('id', $ids)->delete();
            });
            $this->info("Deleted {$deleted} expired tracking events.");

            return self::SUCCESS;
        } finally {
            if ($maria) {
                DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [$name]);
            }
        }
    }
}
