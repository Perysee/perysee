<?php

namespace App\Console\Commands;

use App\Updates\ReleaseFeed;
use App\Updates\Version;
use Illuminate\Console\Command;

class CheckForUpdate extends Command
{
    protected $signature = 'perysee:update:check {--quiet-unless-new : Print only when a newer release exists}';

    protected $description = 'Look for a newer published release and remember the answer';

    public function handle(ReleaseFeed $feed): int
    {
        if (! $feed->enabled()) {
            $this->warn('Update checks are disabled (PERYSEE_UPDATE_CHECK=false).');

            return self::SUCCESS;
        }
        $state = $feed->refresh();
        if ($state['error'] !== null) {
            $this->error($state['error']);

            return self::FAILURE;
        }
        $latest = $feed->available();
        if ($latest !== null) {
            $this->info("Version {$latest->version()} is available (installed: {$feed->currentVersion()}). Run `php artisan perysee:update`.");
        } elseif (! $this->option('quiet-unless-new')) {
            $current = $state['latest'] === null ? 'no published release yet' : 'latest is '.Version::normalize($state['latest']['tag']);
            $this->line("Up to date ({$feed->currentVersion()}; {$current}).");
        }

        return self::SUCCESS;
    }
}
