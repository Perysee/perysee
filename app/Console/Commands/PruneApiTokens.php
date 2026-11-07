<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneApiTokens extends Command
{
    protected $signature = 'perysee:prune-api-tokens {--limit=500 : Tokens deleted per run} {--days=30 : Days after which an expired or revoked token is deleted}';

    protected $description = 'Deletes read API tokens that expired or were revoked more than 30 days ago.';

    public function handle(): int
    {
        $cutoff = now('UTC')->subDays(max(1, (int) $this->option('days')));
        $ids = DB::table('api_tokens')
            ->where(fn ($query) => $query->where('expires_at', '<', $cutoff)->orWhere('revoked_at', '<', $cutoff))
            ->orderBy('id')->limit(max(1, (int) $this->option('limit')))->pluck('id');
        // Nothing to purge is the usual case: skip the delete.
        if ($ids->isNotEmpty()) {
            DB::table('api_tokens')->whereIn('id', $ids)->delete();
        }
        $this->info($ids->count().' API token(s) deleted.');

        return self::SUCCESS;
    }
}
