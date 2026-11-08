<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Analytics\StorageMeter;
use Illuminate\Console\Command;

class StorageReport extends Command
{
    protected $signature = 'perysee:storage-report {--json : Print the figures as JSON}';

    protected $description = 'Read-only: size of each table of the database, largest first, with the space the engine could give back';

    public function handle(StorageMeter $meter): int
    {
        $tables = $meter->tables();
        $total = $tables === null ? null : StorageMeter::total($tables);
        $known = array_filter(array_column($tables ?? [], 'free_bytes'), fn (?int $bytes): bool => $bytes !== null);
        $free = $known === [] ? null : array_sum($known);
        if ($this->option('json')) {
            $this->line(json_encode(['tables' => $tables, 'total_bytes' => $total, 'reclaimable_bytes' => $free], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }
        if ($tables === null) {
            $this->warn('Table sizes are not available on this database.');

            return self::SUCCESS;
        }
        $this->table(['Table', 'Data', 'Indexes', 'Rows (estimate)'], array_map(fn (array $table): array => [
            $table['name'], self::mebibytes($table['data_bytes']), self::mebibytes($table['index_bytes']), $table['row_estimate'] ?? '-',
        ], $tables));
        $this->line('Total: '.self::mebibytes($total));
        $this->line('Reclaimable (estimate): '.($free === null ? 'not available' : self::mebibytes($free)));

        return self::SUCCESS;
    }

    private static function mebibytes(int $bytes): string
    {
        return number_format($bytes / 1048576, 2).' MiB';
    }
}
