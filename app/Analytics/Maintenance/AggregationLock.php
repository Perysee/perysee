<?php

declare(strict_types=1);

namespace App\Analytics\Maintenance;

use Illuminate\Support\Facades\DB;

/**
 * The named lock that the aggregation, the maintenance and the purges share on MariaDB, independent of the cache:
 * a purge never runs while a batch is being consolidated. Without MariaDB (SQLite in tests) there is nothing to take.
 */
final class AggregationLock
{
    private bool $held = false;

    /** Takes the lock without waiting; false when another process holds it. */
    public function acquire(): bool
    {
        if (! $this->supported()) {
            return true;
        }

        return $this->held = (int) DB::selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$this->name()])->acquired === 1;
    }

    public function release(): void
    {
        if ($this->held) {
            $this->held = false;
            DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [$this->name()]);
        }
    }

    private function supported(): bool
    {
        return in_array(DB::getDriverName(), ['mariadb', 'mysql'], true);
    }

    private function name(): string
    {
        return 'perysee:aggregate:'.substr(hash('sha256', DB::connection()->getDatabaseName()), 0, 32);
    }
}
