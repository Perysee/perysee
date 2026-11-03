<?php

declare(strict_types=1);

namespace App\Updates\Dump;

use App\Updates\UpdateException;
use Illuminate\Support\Facades\DB;
use PDO;

/**
 * Dumps through the database connection of the application, so it works on a host that offers no client tool
 * and no terminal. It writes tables, their rows and nothing else (no views, routines, users or grants), which is
 * all this application stores. Rows are read one at a time and written in groups, so memory stays flat.
 */
final class PhpDump implements DumpMethod
{
    private const ROWS_PER_INSERT = 200;

    public function name(): string
    {
        return 'php';
    }

    public function label(): string
    {
        return 'PHP (built into the application)';
    }

    public function available(): bool
    {
        return in_array(DB::getDriverName(), ['mariadb', 'mysql', 'sqlite'], true);
    }

    public function dump(string $path): void
    {
        $output = fopen($path, 'wb');
        if ($output === false) {
            throw new UpdateException('Could not write the database dump.');
        }
        $maria = DB::getDriverName() !== 'sqlite';
        $pdo = DB::connection()->getPdo();
        $buffered = $maria ? $pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY) : null;
        try {
            if ($maria) {
                $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
            }
            fwrite($output, '-- Perysee database dump, '.now('UTC')->toIso8601String()."\n");
            fwrite($output, $maria ? "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n" : "PRAGMA foreign_keys=OFF;\n\n");
            foreach ($this->tables($maria) as $table) {
                $quoted = $this->identifier($table);
                fwrite($output, "DROP TABLE IF EXISTS {$quoted};\n".$this->create($table, $maria).";\n");
                $this->rows($output, $table, $quoted, $pdo);
                fwrite($output, "\n");
            }
            fwrite($output, $maria ? "SET FOREIGN_KEY_CHECKS=1;\n" : "PRAGMA foreign_keys=ON;\n");
        } finally {
            fclose($output);
            if ($maria) {
                $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function tables(bool $maria): array
    {
        if ($maria) {
            return array_map(fn (array $row): string => (string) array_values($row)[0], array_map(fn ($row): array => (array) $row, DB::select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")));
        }

        return array_map(fn ($row): string => $row->name, DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"));
    }

    private function create(string $table, bool $maria): string
    {
        if ($maria) {
            $row = (array) DB::selectOne('SHOW CREATE TABLE '.$this->identifier($table));

            return (string) ($row['Create Table'] ?? array_values($row)[1]);
        }

        return (string) DB::selectOne('SELECT sql FROM sqlite_master WHERE type = ? AND name = ?', ['table', $table])->sql;
    }

    /**
     * @param  resource  $output
     */
    private function rows($output, string $table, string $quoted, PDO $pdo): void
    {
        $group = [];
        $columns = null;
        foreach (DB::cursor("SELECT * FROM {$quoted}") as $row) {
            $row = (array) $row;
            $columns ??= implode(', ', array_map($this->identifier(...), array_keys($row)));
            $group[] = '('.implode(', ', array_map(fn (mixed $value): string => $this->literal($value, $pdo), array_values($row))).')';
            if (count($group) === self::ROWS_PER_INSERT) {
                fwrite($output, "INSERT INTO {$quoted} ({$columns}) VALUES\n".implode(",\n", $group).";\n");
                $group = [];
            }
        }
        if ($group !== []) {
            fwrite($output, "INSERT INTO {$quoted} ({$columns}) VALUES\n".implode(",\n", $group).";\n");
        }
    }

    private function identifier(string $name): string
    {
        return '`'.str_replace('`', '``', $name).'`';
    }

    private function literal(mixed $value, PDO $pdo): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        $value = (string) $value;
        // Bytes that are not text (hashes, packed values) are written in hexadecimal so no encoding can alter them.
        if (! mb_check_encoding($value, 'UTF-8')) {
            return $value === '' ? "''" : '0x'.bin2hex($value);
        }

        return $pdo->quote($value);
    }
}
