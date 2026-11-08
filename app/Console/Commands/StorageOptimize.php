<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Analytics\Maintenance\TableOptimizer;
use App\Analytics\StorageMeter;
use App\Analytics\StorageProjection;
use Illuminate\Console\Command;

/**
 * Gives back the space that deleted rows left inside a table (OPTIMIZE TABLE rebuilds it). It is a tool for the
 * operator, never scheduled: the rebuild needs about the size of the table in free space while it runs, which on
 * shared hosting can push the account over its quota. One table at a time, a simulation unless --force.
 */
class StorageOptimize extends Command
{
    protected $signature = 'perysee:storage-optimize
        {table : The table to rebuild; one of the tables of the database}
        {--dry-run : Only show the estimate (this is already the default)}
        {--force : Rebuild the table for real}';

    protected $description = 'Rebuild one table to recover the space of deleted rows (MariaDB; simulation by default, never scheduled)';

    public function handle(StorageMeter $meter, StorageProjection $projection, TableOptimizer $optimizer): int
    {
        if (! $optimizer->supported()) {
            $this->info('Not applicable: OPTIMIZE TABLE is a MariaDB / MySQL operation, and nothing was done on this database.');

            return self::SUCCESS;
        }
        $name = (string) $this->argument('table');
        $tables = preg_match('/^[A-Za-z0-9_]{1,64}$/', $name) === 1 ? $meter->tables() : [];
        // The name is never put in SQL unless the database itself lists a table of that exact name.
        $table = collect($tables)->firstWhere('name', $name);
        if ($table === null) {
            $this->error('Unknown table "'.mb_substr($name, 0, 64).'". Run perysee:storage-report to list the tables of this database.');

            return self::FAILURE;
        }
        $size = $table['data_bytes'] + $table['index_bytes'];
        $recoverable = $table['free_bytes'];
        $forecast = $projection->forecast();
        $quota = $forecast['quota_bytes'];
        // The rebuild writes a copy beside the table; the day's growth is added so that the room does not vanish under it.
        $needed = $size + max(0, (int) ($forecast['bytes_per_day'] ?? 0));
        $room = $quota === null ? null : $quota - StorageMeter::total($tables);
        $perTable = $optimizer->filePerTable();
        $execute = (bool) $this->option('force') && ! $this->option('dry-run');

        $this->line("Table {$name}: ".$this->size($size).' ('.$this->size($table['data_bytes']).' data, '.$this->size($table['index_bytes']).' indexes).');
        $this->line('  Recoverable: '.($recoverable === null ? 'unknown (the engine gives no estimate)' : '~'.$this->size($recoverable).' (the engine\'s estimate of free space inside the table)').'.');
        $this->line('  innodb_file_per_table: '.($perTable === null ? 'unknown' : ($perTable ? 'ON' : 'OFF')).'.');
        $this->line('  Free space needed while it runs: ~'.$this->size($needed).' (the table size'.($needed > $size ? ' and a day of growth' : '').').');
        $this->line('  Free space in the quota: '.($room === null ? 'unknown, no quota is entered' : $this->size(max(0, $room)).' of '.$this->size($quota)).'.');
        $this->warn('  On shared hosting the quota can be exceeded while the table is rebuilt, and the account may be blocked until it ends.');

        $refusal = match (true) {
            $room !== null && $room < $needed => 'Not enough free space in the quota ('.$this->size(max(0, $room)).' for ~'.$this->size($needed).'). Delete data first (perysee:purge) or raise the quota on the Health page; --force does not override this.',
            $perTable === false => 'innodb_file_per_table is OFF: the rebuilt table would grow the shared tablespace, which never shrinks. Nothing would be given back.',
            default => null,
        };
        if ($refusal !== null) {
            $this->error('Refused: '.$refusal);

            return $execute ? self::FAILURE : self::SUCCESS;
        }
        if ($quota === null) {
            $this->warn('  No quota is entered, so the free space cannot be checked. Enter it on the Health page first; --force runs anyway, at your own risk.');
        }
        if (! $execute) {
            $this->line('Simulation: nothing was rebuilt. Add --force to run OPTIMIZE TABLE on this table (a backup is advised).');

            return self::SUCCESS;
        }

        return $this->optimize($optimizer, $name);
    }

    private function optimize(TableOptimizer $optimizer, string $table): int
    {
        $failed = false;
        foreach ($optimizer->optimize($table) as $message) {
            $failed = $failed || $message['type'] === 'error';
            $this->line("  {$message['op']} {$message['type']}: {$message['text']}");
        }
        if ($failed) {
            $this->error("The engine reported an error: {$table} was not rebuilt.");

            return self::FAILURE;
        }
        $this->info("Rebuilt {$table}. Run perysee:storage-report to see the new size; the daily snapshot follows within a day.");

        return self::SUCCESS;
    }

    private function size(int $bytes): string
    {
        return number_format($bytes / 1048576, 1).' MiB';
    }
}
