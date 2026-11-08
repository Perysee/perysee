<?php

namespace Tests\Unit;

use App\Analytics\Aggregation\UpsertIncrement;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UpsertIncrementTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function sql(array $columns): array
    {
        return array_map(fn ($expression): string => (string) $expression->getValue(DB::connection()->getQueryGrammar()), array_values($columns));
    }

    public function test_sqlite_adds_the_excluded_row(): void
    {
        config(['database.connections.upsert-increment' => ['driver' => 'sqlite', 'database' => ':memory:']]);
        DB::setDefaultConnection('upsert-increment');

        $columns = UpsertIncrement::columns(['pageviews', 'sessions']);

        $this->assertSame(['pageviews', 'sessions'], array_keys($columns));
        $this->assertSame(['pageviews + excluded.pageviews', 'sessions + excluded.sessions'], $this->sql($columns));
    }

    public function test_mysql_and_mariadb_add_the_incoming_values(): void
    {
        config(['database.connections.upsert-increment' => ['driver' => 'mariadb', 'host' => '127.0.0.1', 'database' => 'unused']]);
        DB::setDefaultConnection('upsert-increment');

        $this->assertSame(['hits + VALUES(hits)'], $this->sql(UpsertIncrement::columns(['hits'])));
    }

    public function test_no_metric_gives_no_column(): void
    {
        $this->assertSame([], UpsertIncrement::columns([]));
    }
}
