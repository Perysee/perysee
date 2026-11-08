<?php

namespace Tests\Feature;

use App\Analytics\StorageMeter;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StorageSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private function row(string $day, string $table = 'events', int $data = 1000): array
    {
        return ['taken_on' => $day, 'table_name' => $table, 'data_bytes' => $data, 'index_bytes' => 100, 'row_estimate' => null];
    }

    private function today(): string
    {
        return now('UTC')->toDateString();
    }

    private function daysAgo(int $days): string
    {
        return now('UTC')->subDays($days)->toDateString();
    }

    public function test_the_command_records_one_row_per_table_for_today(): void
    {
        $this->artisan('perysee:storage-snapshot')->assertSuccessful();

        $measured = app(StorageMeter::class)->tables() ?? [];
        $this->assertSame(count($measured), DB::table('table_size_snapshots')->where('taken_on', $this->today())->count());
    }

    public function test_running_it_again_the_same_day_changes_nothing(): void
    {
        DB::table('table_size_snapshots')->insert($this->row($this->today(), 'events', 42));

        $this->artisan('perysee:storage-snapshot')->assertSuccessful();

        $this->assertSame(1, DB::table('table_size_snapshots')->count());
        $this->assertSame(42, (int) DB::table('table_size_snapshots')->value('data_bytes'));
    }

    public function test_when_the_snapshot_exists_the_command_costs_one_read(): void
    {
        DB::table('table_size_snapshots')->insert($this->row($this->today()));
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->artisan('perysee:storage-snapshot')->assertSuccessful();

        $this->assertCount(1, $queries, implode(' | ', $queries));
    }

    public function test_snapshots_older_than_400_days_are_purged_after_the_insertion(): void
    {
        DB::table('table_size_snapshots')->insert([
            $this->row($this->daysAgo(401)),
            $this->row($this->daysAgo(401), 'goals'),
            $this->row($this->daysAgo(500)),
            $this->row($this->daysAgo(400)),
            $this->row($this->daysAgo(10)),
        ]);

        $this->artisan('perysee:storage-snapshot')->assertSuccessful();

        $days = DB::table('table_size_snapshots')->distinct()->orderBy('taken_on')->pluck('taken_on')->map(fn ($day) => substr((string) $day, 0, 10))->all();
        $this->assertSame([$this->daysAgo(400), $this->daysAgo(10)], array_slice($days, 0, 2));
    }

    public function test_the_purge_is_bounded_per_run(): void
    {
        $rows = [];
        for ($i = 0; $i < 230; $i++) {
            $rows[] = $this->row($this->daysAgo(401 + $i));
        }
        DB::table('table_size_snapshots')->insert($rows);

        $this->artisan('perysee:storage-snapshot')->assertSuccessful();

        $old = DB::table('table_size_snapshots')->where('taken_on', '<', $this->daysAgo(400))->count();
        $this->assertSame(30, $old, '20 batches of 10 days per run.');
    }

    public function test_the_command_is_scheduled_hourly_without_overlap(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command, 'perysee:storage-snapshot'));

        $this->assertNotNull($event);
        $this->assertSame('0 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }
}
