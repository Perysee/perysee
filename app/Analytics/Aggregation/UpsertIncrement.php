<?php

namespace App\Analytics\Aggregation;

use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;

final class UpsertIncrement
{
    /**
     * The update columns of an upsert that adds the incoming value to the stored one (SQLite names the new row
     * `excluded`, MariaDB and MySQL read it with VALUES()).
     *
     * @param  list<string>  $metrics
     * @return array<string, Expression>
     */
    public static function columns(array $metrics): array
    {
        $sqlite = DB::getDriverName() === 'sqlite';
        $columns = [];
        foreach ($metrics as $metric) {
            $columns[$metric] = DB::raw($metric.' + '.($sqlite ? 'excluded.'.$metric : 'VALUES('.$metric.')'));
        }

        return $columns;
    }
}
