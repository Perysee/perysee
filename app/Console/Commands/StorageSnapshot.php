<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Analytics\StorageMeter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class StorageSnapshot extends Command
{
    /** Days of history kept: more than a year, to compare a season with the same one a year earlier. */
    public const RETENTION_DAYS = 400;

    /** Days purged per batch, and batches per run: a long backlog is worked off over several runs. */
    private const PURGE_BATCH = 10;

    private const MAX_BATCHES = 20;

    protected $signature = 'perysee:storage-snapshot';

    protected $description = 'Record the size of each table once a day and drop snapshots older than 400 days';

    public function handle(StorageMeter $meter): int
    {
        $today = now('UTC')->toDateString();
        // The usual hourly pass: today is already recorded, so this single read is the whole cost.
        if (DB::table('table_size_snapshots')->where('taken_on', $today)->exists()) {
            return self::SUCCESS;
        }
        $tables = $meter->tables();
        if ($tables === null) {
            $this->warn('Table sizes are not available on this database.');

            return self::SUCCESS;
        }
        foreach (array_chunk($tables, 100) as $chunk) {
            DB::table('table_size_snapshots')->insertOrIgnore(array_map(fn (array $table): array => [
                'taken_on' => $today,
                'table_name' => $table['name'],
                'data_bytes' => $table['data_bytes'],
                'index_bytes' => $table['index_bytes'],
                'row_estimate' => $table['row_estimate'],
            ], $chunk));
        }
        $this->info('Recorded the size of '.count($tables).' tables.');
        $this->purge();

        return self::SUCCESS;
    }

    private function purge(): void
    {
        $cutoff = now('UTC')->subDays(self::RETENTION_DAYS)->toDateString();
        for ($batch = 0; $batch < self::MAX_BATCHES; $batch++) {
            $days = DB::table('table_size_snapshots')->where('taken_on', '<', $cutoff)->distinct()->orderBy('taken_on')->limit(self::PURGE_BATCH)->pluck('taken_on');
            if ($days->isEmpty()) {
                return;
            }
            DB::table('table_size_snapshots')->whereIn('taken_on', $days)->delete();
        }
    }
}
