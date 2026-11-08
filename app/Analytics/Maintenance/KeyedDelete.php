<?php

namespace App\Analytics\Maintenance;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class KeyedDelete
{
    /** Bindings per statement: a long list is cut in several, whatever the driver's limit. */
    private const BINDINGS = 10000;

    /**
     * Delete rows selected by their key columns with one statement per batch instead of one per row.
     * Several columns compare as row values, which MariaDB and SQLite both accept.
     *
     * @param  list<string>  $keys
     * @param  Collection<int, object>  $rows  the key columns of each row
     * @param  int|null  $siteId  restricts keys that are only unique inside a site
     */
    public static function rows(string $table, array $keys, Collection $rows, ?int $siteId = null): void
    {
        foreach ($rows->chunk(intdiv(self::BINDINGS, count($keys))) as $chunk) {
            $query = DB::table($table);
            if ($siteId !== null) {
                $query->where('website_id', $siteId);
            }
            if (count($keys) === 1) {
                $query->whereIn($keys[0], $chunk->pluck($keys[0])->all())->delete();

                continue;
            }
            $tuple = '('.implode(', ', array_fill(0, count($keys), '?')).')';
            $query->whereRaw(
                '('.$query->getGrammar()->columnize($keys).') in ('.implode(', ', array_fill(0, $chunk->count(), $tuple)).')',
                $chunk->flatMap(fn (object $row): array => array_values((array) $row))->all(),
            )->delete();
        }
    }
}
