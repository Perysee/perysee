<?php

namespace App\Analytics;

use Illuminate\Support\Facades\DB;

/**
 * Measures how much room each table of the instance's database takes, from the engine's own statistics: one read of
 * information_schema on MariaDB or MySQL (row counts are the engine's estimate, never a COUNT(*)), the dbstat
 * virtual table on SQLite. Where neither is available the answer is null, never an exception.
 */
class StorageMeter
{
    /**
     * Largest first. `free_bytes` is the space the engine could give back (MariaDB's data_free), null elsewhere.
     *
     * @return list<array{name: string, data_bytes: int, index_bytes: int, row_estimate: ?int, free_bytes: ?int}>|null
     */
    public function tables(): ?array
    {
        try {
            $tables = match (DB::getDriverName()) {
                'mariadb', 'mysql' => $this->fromInformationSchema(),
                'sqlite' => $this->fromDbstat(),
                default => null,
            };
        } catch (\Throwable) {
            // A diagnostic must never break the page or the command that asks for it.
            return null;
        }
        if ($tables === null) {
            return null;
        }
        usort($tables, fn (array $a, array $b): int => [$b['data_bytes'] + $b['index_bytes'], $a['name']] <=> [$a['data_bytes'] + $a['index_bytes'], $b['name']]);

        return $tables;
    }

    public function totalBytes(): ?int
    {
        $tables = $this->tables();

        return $tables === null ? null : self::total($tables);
    }

    /** @param  list<array{data_bytes: int, index_bytes: int}>  $tables */
    public static function total(array $tables): int
    {
        return array_sum(array_map(fn (array $table): int => $table['data_bytes'] + $table['index_bytes'], $tables));
    }

    private function fromInformationSchema(): array
    {
        $rows = DB::select("SELECT table_name AS name, data_length AS data_bytes, index_length AS index_bytes, table_rows AS row_estimate, data_free AS free_bytes FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'");

        return array_map(fn (object $row): array => [
            'name' => (string) $row->name,
            'data_bytes' => (int) $row->data_bytes,
            'index_bytes' => (int) $row->index_bytes,
            'row_estimate' => $row->row_estimate === null ? null : (int) $row->row_estimate,
            'free_bytes' => $row->free_bytes === null ? null : (int) $row->free_bytes,
        ], $rows);
    }

    private function fromDbstat(): array
    {
        // Pages of each table and of its indexes (automatic ones included), counted under the table's name.
        $rows = DB::select("SELECT m.tbl_name AS name, m.type AS kind, SUM(d.pgsize) AS bytes FROM dbstat d JOIN sqlite_master m ON m.name = d.name WHERE m.type IN ('table', 'index') AND m.tbl_name NOT LIKE 'sqlite\\_%' ESCAPE '\\' GROUP BY m.tbl_name, m.type");
        $tables = [];
        foreach ($rows as $row) {
            $tables[$row->name] ??= ['name' => (string) $row->name, 'data_bytes' => 0, 'index_bytes' => 0, 'row_estimate' => null, 'free_bytes' => null];
            $tables[$row->name][$row->kind === 'table' ? 'data_bytes' : 'index_bytes'] += (int) $row->bytes;
        }

        return array_values($tables);
    }
}
