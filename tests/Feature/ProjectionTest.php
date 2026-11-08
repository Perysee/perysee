<?php

namespace Tests\Feature;

use App\Analytics\InstanceHealth;
use App\Analytics\StorageProjection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProjectionTest extends TestCase
{
    use RefreshDatabase;

    private const MIB = 1048576;

    /** One snapshot per listed day, the key being how many days ago, the value the total in MiB (split in two tables). */
    private function snapshots(array $mebibytesByDaysAgo): void
    {
        foreach ($mebibytesByDaysAgo as $daysAgo => $mebibytes) {
            $day = now('UTC')->subDays($daysAgo)->toDateString();
            $total = $mebibytes * self::MIB;
            DB::table('table_size_snapshots')->insert([
                ['taken_on' => $day, 'table_name' => 'events', 'data_bytes' => intdiv($total, 2), 'index_bytes' => 0, 'row_estimate' => null],
                ['taken_on' => $day, 'table_name' => 'stats_daily', 'data_bytes' => $total - intdiv($total, 2), 'index_bytes' => 0, 'row_estimate' => null],
            ]);
        }
    }

    /** Ten days growing by 100 MiB a day, 100 MiB nine days ago and 1000 MiB today. */
    private function linearTenDays(): void
    {
        $this->snapshots(array_combine(range(9, 0), range(100, 1000, 100)));
    }

    private function quota(int $mebibytes): void
    {
        app(InstanceHealth::class)->put('storage_quota_bytes', $mebibytes * self::MIB);
    }

    private function forecast(): array
    {
        return app(StorageProjection::class)->forecast();
    }

    public function test_a_linear_series_gives_the_days_left_before_the_quota(): void
    {
        $this->linearTenDays();
        $this->quota(1500);

        $forecast = $this->forecast();

        $this->assertSame('projected', $forecast['status']);
        $this->assertSame(5, $forecast['days']);
        $this->assertSame(100 * self::MIB, $forecast['bytes_per_day']);
        $this->assertStringContainsString('5 days', $forecast['message']);
    }

    public function test_a_partial_day_is_rounded_up(): void
    {
        $this->linearTenDays();
        $this->quota(1050);

        $this->assertSame(1, $this->forecast()['days']);
    }

    public function test_missing_days_are_placed_on_the_calendar_not_squeezed_together(): void
    {
        // 100 MiB per calendar day, but only seven snapshots spread over nineteen days.
        $this->snapshots(array_combine([19, 17, 14, 12, 8, 3, 0], [100, 300, 600, 800, 1200, 1700, 2000]));
        $this->quota(2500);

        $forecast = $this->forecast();

        $this->assertSame('projected', $forecast['status']);
        $this->assertSame(5, $forecast['days']);
    }

    public function test_fewer_than_seven_days_of_data_makes_the_estimate_unavailable(): void
    {
        $this->snapshots(array_combine(range(5, 0), range(100, 600, 100)));
        $this->quota(1500);

        $forecast = $this->forecast();

        $this->assertSame('unavailable', $forecast['status']);
        $this->assertNull($forecast['days']);
        $this->assertSame('Estimate unavailable: at least 7 days of measurements are needed.', $forecast['message']);
        $this->assertSame([], $this->storageAlerts());
    }

    public function test_seven_days_are_enough(): void
    {
        $this->snapshots(array_combine(range(6, 0), range(100, 700, 100)));
        $this->quota(1000);

        $this->assertSame(3, $this->forecast()['days']);
    }

    public function test_data_older_than_thirty_days_is_ignored(): void
    {
        $this->snapshots([60 => 5000, 50 => 4000, 45 => 3000, 40 => 2000, 35 => 1000, 31 => 900]);
        $this->snapshots(array_combine(range(3, 0), range(100, 400, 100)));
        $this->quota(1000);

        $this->assertSame('unavailable', $this->forecast()['status']);
    }

    public function test_no_growth_or_a_shrinking_database_raises_no_alert(): void
    {
        $this->snapshots(array_fill_keys(range(9, 0), 900));
        $this->quota(1000);

        $this->assertSame('flat', $this->forecast()['status']);
        $this->assertSame([], $this->storageAlerts());

        DB::table('table_size_snapshots')->delete();
        $this->snapshots(array_combine(range(9, 0), range(1000, 100, -100)));

        $this->assertSame('flat', $this->forecast()['status']);
        $this->assertNull($this->forecast()['days']);
        $this->assertSame([], $this->storageAlerts());
    }

    public function test_without_a_quota_nothing_is_projected_and_nothing_is_read_from_the_snapshots(): void
    {
        $this->linearTenDays();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->assertSame('no_quota', $this->forecast()['status']);
        $this->assertSame([], $this->storageAlerts());
        $this->assertEmpty(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'table_size_snapshots')));
    }

    public function test_a_projection_under_thirty_days_raises_the_storage_alert(): void
    {
        $this->linearTenDays();
        $this->quota(1500);

        $alerts = $this->storageAlerts();

        $this->assertCount(1, $alerts);
        $this->assertStringContainsString('5 days', $alerts[0]['message']);
    }

    public function test_a_projection_of_thirty_days_or_more_raises_nothing(): void
    {
        $this->linearTenDays();
        $this->quota(4000);

        $this->assertSame(30, $this->forecast()['days']);
        $this->assertSame([], $this->storageAlerts());
    }

    public function test_a_quota_already_reached_is_reported_even_with_little_data(): void
    {
        $this->snapshots([1 => 900, 0 => 1100]);
        $this->quota(1000);

        $forecast = $this->forecast();

        $this->assertSame('reached', $forecast['status']);
        $this->assertSame(0, $forecast['days']);
        $this->assertCount(1, $this->storageAlerts());
    }

    public function test_the_storage_alert_comes_with_the_other_warnings_and_not_instead_of_them(): void
    {
        $this->linearTenDays();
        $this->quota(1500);
        app(InstanceHealth::class)->put('heartbeat', now('UTC')->toIso8601String());

        $this->assertSame(['storage'], array_column(app(InstanceHealth::class)->attention(), 'code'));

        app(InstanceHealth::class)->forget('heartbeat');

        $this->assertSame(['scheduler'], array_column(app(InstanceHealth::class)->attention(), 'code'));
    }

    /** @return list<array{code: string, message: string}> */
    private function storageAlerts(): array
    {
        app(InstanceHealth::class)->put('heartbeat', now('UTC')->toIso8601String());

        return array_values(array_filter(app(InstanceHealth::class)->attention(), fn (array $item): bool => $item['code'] === 'storage'));
    }
}
