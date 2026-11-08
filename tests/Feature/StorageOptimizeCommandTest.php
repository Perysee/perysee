<?php

namespace Tests\Feature;

use App\Analytics\InstanceHealth;
use App\Analytics\Maintenance\TableOptimizer;
use App\Analytics\StorageMeter;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StorageOptimizeCommandTest extends TestCase
{
    use RefreshDatabase;

    private const MIB = 1048576;

    /** @var list<string> */
    public array $optimized = [];

    /** @var list<array{op: string, type: string, text: string}> */
    public array $answer = [['op' => 'optimize', 'type' => 'note', 'text' => 'Table does not support optimize, doing recreate + analyze instead'], ['op' => 'optimize', 'type' => 'status', 'text' => 'OK']];

    private ?bool $perTable = true;

    /** The database as MariaDB would describe it: 1 GiB in all, the events table being 800 MiB with 150 MiB free inside. */
    private function mariaDb(?int $quotaMib = null, ?bool $perTable = true): void
    {
        $this->perTable = $perTable;
        $test = $this;
        $this->app->instance(StorageMeter::class, new class extends StorageMeter
        {
            public function tables(): ?array
            {
                return [
                    ['name' => 'events', 'data_bytes' => 600 * 1048576, 'index_bytes' => 200 * 1048576, 'row_estimate' => 1000, 'free_bytes' => 150 * 1048576],
                    ['name' => 'stats_daily', 'data_bytes' => 200 * 1048576, 'index_bytes' => 24 * 1048576, 'row_estimate' => 10, 'free_bytes' => 0],
                ];
            }
        });
        $this->app->instance(TableOptimizer::class, new class($test) extends TableOptimizer
        {
            public function __construct(private readonly StorageOptimizeCommandTest $test) {}

            public function supported(): bool
            {
                return true;
            }

            public function filePerTable(): ?bool
            {
                return $this->test->perTable();
            }

            public function optimize(string $table): array
            {
                $this->test->optimized[] = $table;

                return $this->test->answer;
            }
        });
        if ($quotaMib !== null) {
            app(InstanceHealth::class)->put('storage_quota_bytes', $quotaMib * self::MIB);
        }
    }

    public function perTable(): ?bool
    {
        return $this->perTable;
    }

    public function test_on_sqlite_it_says_it_is_not_applicable_and_succeeds(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('The command does nothing on SQLite only; this run uses '.DB::getDriverName().'.');
        }

        $this->artisan('perysee:storage-optimize', ['table' => 'events', '--force' => true])->expectsOutputToContain('Not applicable')->assertSuccessful();
        $this->artisan('perysee:storage-optimize', ['table' => 'no_such_table'])->expectsOutputToContain('Not applicable')->assertSuccessful();
        $this->assertSame('sqlite', DB::getDriverName());
    }

    public function test_without_force_it_only_estimates_and_never_rebuilds(): void
    {
        $this->mariaDb(quotaMib: 4096);

        $this->artisan('perysee:storage-optimize', ['table' => 'events'])
            ->expectsOutputToContain('Table events: 800.0 MiB')
            ->expectsOutputToContain('~150.0 MiB')
            ->expectsOutputToContain('innodb_file_per_table: ON')
            ->expectsOutputToContain('quota can be exceeded')
            ->expectsOutputToContain('Simulation: nothing was rebuilt')
            ->assertSuccessful();
        $this->artisan('perysee:storage-optimize', ['table' => 'events', '--force' => true, '--dry-run' => true])->assertSuccessful();

        $this->assertSame([], $this->optimized);
    }

    public function test_an_unknown_table_or_a_name_that_is_not_a_plain_identifier_is_refused(): void
    {
        $this->mariaDb(quotaMib: 4096);

        foreach (['users', 'events; DROP TABLE events', 'events`', '../events', 'Events ', ''] as $name) {
            $this->artisan('perysee:storage-optimize', ['table' => $name, '--force' => true])->assertFailed();
        }

        $this->assertSame([], $this->optimized);
    }

    public function test_with_force_and_room_it_rebuilds_that_one_table_and_prints_the_engine_answer(): void
    {
        $this->mariaDb(quotaMib: 4096);

        $this->artisan('perysee:storage-optimize', ['table' => 'events', '--force' => true])
            ->expectsOutputToContain('recreate + analyze')
            ->expectsOutputToContain('Rebuilt events')
            ->assertSuccessful();

        $this->assertSame(['events'], $this->optimized);
    }

    public function test_an_engine_error_is_a_failure(): void
    {
        $this->mariaDb(quotaMib: 4096);
        $this->answer = [['op' => 'optimize', 'type' => 'error', 'text' => 'Lock wait timeout exceeded']];

        $this->artisan('perysee:storage-optimize', ['table' => 'events', '--force' => true])->expectsOutputToContain('was not rebuilt')->assertFailed();
    }

    public function test_it_refuses_when_the_free_space_of_the_quota_is_too_small_even_with_force(): void
    {
        // 1 GiB in use of 1.5 GiB: 512 MiB free for a table of 800 MiB.
        $this->mariaDb(quotaMib: 1536);

        $this->artisan('perysee:storage-optimize', ['table' => 'events', '--force' => true])->expectsOutputToContain('Not enough free space')->assertFailed();
        $this->artisan('perysee:storage-optimize', ['table' => 'events'])->expectsOutputToContain('Not enough free space')->assertSuccessful();
        $this->artisan('perysee:storage-optimize', ['table' => 'stats_daily', '--force' => true])->assertSuccessful();

        $this->assertSame(['stats_daily'], $this->optimized, 'The small table fits: the guard compares with the size of the table asked for.');
    }

    public function test_the_projected_growth_of_a_day_is_part_of_the_space_needed(): void
    {
        // 1 GiB in use, 1.78 GiB quota: exactly 800 MiB free for 800 MiB, until the database grows 30 MiB a day.
        $this->mariaDb(quotaMib: 1824);
        foreach (range(0, 9) as $day) {
            DB::table('table_size_snapshots')->insert(['taken_on' => now('UTC')->subDays(9 - $day)->toDateString(), 'table_name' => 'events', 'data_bytes' => (724 + 30 * $day) * self::MIB, 'index_bytes' => 0, 'row_estimate' => 1]);
        }

        $this->artisan('perysee:storage-optimize', ['table' => 'events', '--force' => true])->expectsOutputToContain('a day of growth')->expectsOutputToContain('Not enough free space')->assertFailed();

        $this->assertSame([], $this->optimized);
    }

    public function test_without_a_quota_the_free_space_is_unknown_and_the_simulation_says_why(): void
    {
        $this->mariaDb();

        $this->artisan('perysee:storage-optimize', ['table' => 'events'])->expectsOutputToContain('No quota is entered')->assertSuccessful();
        $this->assertSame([], $this->optimized);

        $this->artisan('perysee:storage-optimize', ['table' => 'events', '--force' => true])->expectsOutputToContain('at your own risk')->assertSuccessful();
        $this->assertSame(['events'], $this->optimized);
    }

    public function test_it_refuses_when_each_table_has_no_file_of_its_own(): void
    {
        $this->mariaDb(quotaMib: 4096, perTable: false);

        $this->artisan('perysee:storage-optimize', ['table' => 'events', '--force' => true])->expectsOutputToContain('innodb_file_per_table is OFF')->assertFailed();

        $this->assertSame([], $this->optimized);
    }

    public function test_it_is_never_scheduled(): void
    {
        $scheduled = collect(app(Schedule::class)->events())->contains(fn ($event) => str_contains($event->command, 'storage-optimize'));

        $this->assertFalse($scheduled);
    }
}
