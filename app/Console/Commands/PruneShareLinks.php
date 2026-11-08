<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Analytics\Maintenance\RetentionPurge;
use Illuminate\Console\Command;

/**
 * Public share links that expired or were revoked more than 30 days ago are of no use any more (SOC-04). Nothing
 * else references them, and the access log keeps its own record of their creation.
 */
class PruneShareLinks extends Command
{
    private const MAX_BATCHES = 20;

    protected $signature = 'perysee:prune-share-links {--limit=1000 : Maximum rows deleted per batch}';

    protected $description = 'Delete share links that expired or were revoked more than 30 days ago';

    public function handle(RetentionPurge $purge): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);
        if ($limit === false) {
            $this->error('Use a limit between 1 and 10000.');

            return self::FAILURE;
        }
        $cutoff = now('UTC')->subDays(30);
        // Nothing to purge is the usual case: one read, no delete.
        if (! $purge->pending('share-links', $cutoff)) {
            return self::SUCCESS;
        }
        $this->info('Removed '.$purge->purge('share-links', $cutoff, $limit, self::MAX_BATCHES)['share_links'].' share links.');

        return self::SUCCESS;
    }
}
