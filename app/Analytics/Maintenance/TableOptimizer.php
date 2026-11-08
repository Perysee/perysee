<?php

declare(strict_types=1);

namespace App\Analytics\Maintenance;

use Illuminate\Support\Facades\DB;

/**
 * The engine side of `perysee:storage-optimize`: what only MariaDB (or MySQL) can answer or do. It is a class of its
 * own so that the decision logic of the command is tested without a MariaDB server.
 */
class TableOptimizer
{
    /** Seconds a table lock may be waited for: queries pile up behind a waiting OPTIMIZE, so it gives up quickly. */
    private const LOCK_WAIT_SECONDS = 15;

    /** OPTIMIZE TABLE exists on MariaDB and MySQL; SQLite has no equivalent that this tool offers. */
    public function supported(): bool
    {
        return in_array(DB::getDriverName(), ['mariadb', 'mysql'], true);
    }

    /** Whether each InnoDB table has its own file, which is what lets a rebuilt table give space back to the disk. Null when unknown. */
    public function filePerTable(): ?bool
    {
        try {
            $row = DB::selectOne("SHOW VARIABLES LIKE 'innodb_file_per_table'");
        } catch (\Throwable) {
            return null;
        }
        $value = $row === null ? null : strtoupper((string) (((array) $row)['Value'] ?? ''));

        return $value === null ? null : in_array($value, ['ON', '1'], true);
    }

    /**
     * Rebuilds one table. The name must already be one of the tables of the database: it is quoted here, never trusted.
     *
     * @return list<array{op: string, type: string, text: string}> The engine's answer, one row per step.
     */
    public function optimize(string $table): array
    {
        DB::statement('SET SESSION lock_wait_timeout = '.self::LOCK_WAIT_SECONDS);
        $messages = [];
        foreach (DB::select('OPTIMIZE TABLE '.DB::connection()->getQueryGrammar()->wrapTable($table)) as $row) {
            $row = array_change_key_case((array) $row);
            $messages[] = ['op' => (string) ($row['op'] ?? ''), 'type' => (string) ($row['msg_type'] ?? ''), 'text' => (string) ($row['msg_text'] ?? '')];
        }

        return $messages;
    }
}
