<?php

declare(strict_types=1);

namespace Perysee\Modules\Annotations;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneCommand extends Command
{
    protected $signature = 'annotations:prune';

    protected $description = 'Delete annotations older than the configured retention';

    public function handle(Notes $notes): int
    {
        $deleted = DB::table('annotations_notes')->where('noted_on', '<', now()->subDays($notes->retentionDays())->toDateString())->delete();
        $this->info("Deleted {$deleted} annotations older than {$notes->retentionDays()} days.");

        return self::SUCCESS;
    }
}
