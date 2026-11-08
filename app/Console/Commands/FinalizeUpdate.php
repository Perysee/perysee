<?php

namespace App\Console\Commands;

use App\Analytics\Continuity\ContinuityLog;
use App\Analytics\InstanceHealth;
use App\Updates\Version;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class FinalizeUpdate extends Command
{
    protected $signature = 'perysee:update:finalize {--expect= : The version these files must be}';

    protected $description = 'Second half of an update, run by the new version in a fresh process: migrations, caches, reopening';

    protected $hidden = true;

    public function handle(): int
    {
        $expected = (string) $this->option('expect');
        if ($expected === '' || Version::normalize($expected) !== Version::normalize((string) config('perysee.version'))) {
            $this->error('These files are version '.config('perysee.version').', not '.$expected.'.');

            return self::FAILURE;
        }
        $this->line('Applying migrations');
        if (Artisan::call('migrate', ['--force' => true]) !== 0) {
            $this->error('Migrations failed: '.trim(Artisan::output()));

            return self::FAILURE;
        }
        $this->noteVersions();
        Artisan::call('optimize:clear');
        DB::table('analytics_state')->count();
        $this->line('Reopening the instance');
        Artisan::call('up');

        return self::SUCCESS;
    }

    /**
     * Leaves a note in the continuity log when the Core or the tracker is not the version seen at the previous update,
     * then remembers the current ones. The first run only remembers: the version before it is not known. A failure
     * here never fails the update, which has already migrated the database.
     */
    private function noteVersions(): void
    {
        try {
            $health = app(InstanceHealth::class);
            $log = app(ContinuityLog::class);
            $today = now('UTC')->toDateString();
            DB::transaction(function () use ($health, $log, $today): void {
                foreach (['core_version' => ['last_seen_version', (string) config('perysee.version')], 'tracker_version' => ['last_seen_tracker_version', (string) config('perysee.tracker_version')]] as $kind => [$state, $current]) {
                    $seen = $health->get($state);
                    if ($seen === $current) {
                        continue;
                    }
                    if (is_string($seen) && Version::normalize($seen) !== Version::normalize($current)) {
                        $log->noteOnce($kind, null, 'all', $today, ['from' => $seen, 'to' => $current]);
                    }
                    $health->put($state, $current);
                }
            });
        } catch (\Throwable $exception) {
            report($exception);
            $this->warn('The continuity log could not be updated; the update itself is complete.');
        }
    }
}
