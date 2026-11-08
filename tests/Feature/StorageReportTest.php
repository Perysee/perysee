<?php

namespace Tests\Feature;

use App\Analytics\StorageMeter;
use App\Installation\InstallationState;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class StorageReportTest extends TestCase
{
    use RefreshDatabase;

    private function onMariaDb(): bool
    {
        return in_array(DB::getDriverName(), ['mariadb', 'mysql'], true);
    }

    public function test_the_size_of_each_table_is_measured_on_mariadb(): void
    {
        if (! $this->onMariaDb()) {
            $this->markTestSkipped('information_schema.tables is only measured on MariaDB or MySQL.');
        }

        $tables = app(StorageMeter::class)->tables();

        $this->assertNotEmpty($tables);
        $events = collect($tables)->firstWhere('name', 'events');
        $this->assertNotNull($events);
        $this->assertGreaterThan(0, $events['data_bytes'] + $events['index_bytes']);
        $this->assertIsInt($events['row_estimate']);
        $this->assertIsInt($events['free_bytes']);
    }

    public function test_sqlite_is_measured_through_dbstat_or_gives_null_without_an_exception(): void
    {
        if ($this->onMariaDb()) {
            $this->markTestSkipped('SQLite only.');
        }

        $tables = app(StorageMeter::class)->tables();

        if ($tables === null) {
            $this->assertNull(app(StorageMeter::class)->totalBytes());

            return;
        }
        $events = collect($tables)->firstWhere('name', 'events');
        $this->assertGreaterThan(0, $events['data_bytes']);
        $this->assertNull($events['row_estimate']);
        $this->assertNull($events['free_bytes']);
        $this->assertSame(array_sum(array_map(fn (array $t): int => $t['data_bytes'] + $t['index_bytes'], $tables)), app(StorageMeter::class)->totalBytes());
    }

    public function test_tables_come_largest_first(): void
    {
        $tables = app(StorageMeter::class)->tables() ?? [];
        $sizes = array_map(fn (array $t): int => $t['data_bytes'] + $t['index_bytes'], $tables);

        $sorted = $sizes;
        rsort($sorted);
        $this->assertSame($sorted, $sizes);
    }

    public function test_the_report_command_lists_the_tables_and_the_total(): void
    {
        $this->artisan('perysee:storage-report')->assertSuccessful()->expectsOutputToContain('Total');
    }

    public function test_the_report_command_answers_in_json_and_writes_nothing(): void
    {
        $output = new BufferedOutput;
        Artisan::call('perysee:storage-report', ['--json' => true], $output);
        $json = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(['tables', 'total_bytes', 'reclaimable_bytes'], array_keys($json));
        $this->assertSame(0, DB::table('table_size_snapshots')->count());
        if ($json['tables'] !== null) {
            $this->assertSame(['name', 'data_bytes', 'index_bytes', 'row_estimate', 'free_bytes'], array_keys($json['tables'][0]));
        }
    }

    public function test_the_health_page_shows_at_most_the_eight_largest_tables(): void
    {
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
        $admin = User::factory()->create(['is_admin' => true]);
        $expected = array_slice(app(StorageMeter::class)->tables() ?? [], 0, 8);

        // SQLite measures the same bytes before and during the request; the InnoDB estimates move with the rows the
        // request itself writes (a device row), so on MariaDB the page is checked for its shape and its order.
        if (DB::getDriverName() === 'sqlite') {
            $this->actingAs($admin)->get('/health')->assertInertia(fn (Assert $page) => $page
                ->where('health.largest_tables', $expected)
                ->where('health.database_bytes', app(StorageMeter::class)->totalBytes()));
        } else {
            $this->actingAs($admin)->get('/health')->assertInertia(fn (Assert $page) => $page
                ->where('health.largest_tables', function ($tables): bool {
                    $tables = array_values(collect($tables)->all());
                    $sizes = array_map(fn ($table): int => $table['data_bytes'] + $table['index_bytes'], $tables);
                    $sorted = $sizes;
                    rsort($sorted);

                    return $tables !== [] && count($tables) <= 8 && $sizes === $sorted
                        && array_keys($tables[0]) === ['name', 'data_bytes', 'index_bytes', 'row_estimate', 'free_bytes'];
                })
                ->where('health.database_bytes', fn ($bytes): bool => is_int($bytes) && $bytes > 0));
        }
        $this->assertLessThanOrEqual(8, count($expected));
    }
}
