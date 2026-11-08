<?php

namespace Tests\Feature;

use App\Analytics\InstanceHealth;
use App\Analytics\Maintenance\TableOptimizer;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** What only a real MariaDB answers: the dedicated test database, never an instance's. */
class StorageOptimizeMariaDbTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mariadb') {
            $this->markTestSkipped('Requires the dedicated MariaDB test database.');
        }
    }

    public function test_the_engine_reports_innodb_file_per_table_in_one_query(): void
    {
        $this->assertContains(app(TableOptimizer::class)->filePerTable(), [true, false]);
    }

    public function test_a_real_rebuild_of_a_small_table_succeeds_and_keeps_its_rows(): void
    {
        DB::table('analytics_state')->insert(['name' => 'optimize-probe', 'value' => '1', 'updated_at' => now('UTC')]);
        app(InstanceHealth::class)->put('storage_quota_bytes', 16 * 1073741824);

        $this->artisan('perysee:storage-optimize', ['table' => 'analytics_state'])->expectsOutputToContain('Simulation')->assertSuccessful();
        $this->artisan('perysee:storage-optimize', ['table' => 'analytics_state', '--force' => true])->expectsOutputToContain('Rebuilt analytics_state')->assertSuccessful();

        $this->assertSame(1, DB::table('analytics_state')->where('name', 'optimize-probe')->count());
    }

    public function test_a_table_name_is_quoted_and_never_trusted(): void
    {
        $this->artisan('perysee:storage-optimize', ['table' => 'analytics_state`; DROP TABLE users; --', '--force' => true])->assertFailed();

        $this->assertSame(0, DB::table('users')->count());
    }
}
