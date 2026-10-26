<?php

namespace Tests\Feature;

use App\Analytics\Aggregation\Aggregator;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MariaDbReliabilityTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mariadb') {
            $this->markTestSkipped('Requires the dedicated MariaDB test database.');
        }
    }

    public static function stages(): array
    {
        return array_map(fn ($stage) => [$stage], ['selected', 'sessions', 'counters', 'hourly', 'dimensions', 'marked', 'committed']);
    }

    #[DataProvider('stages')]
    public function test_a_killed_aggregator_resumes_without_loss_or_double_counting(string $stage): void
    {
        $site = Website::factory()->create(['tracking_mode' => 'sessions']);
        DB::table('events')->insert(['website_id' => $site->id, 'received_at' => now('UTC')->subDays(100)->format('Y-m-d H:i:s.v'), 'path' => '/', 'session_key' => str_repeat('c', 64), 'consent_given' => true, 'tracking_mode' => 'sessions']);
        $child = new Process([PHP_BINARY, base_path('tests/Support/aggregate-child.php'), $stage], base_path());
        $child->start();
        try {
            $deadline = microtime(true) + 10;
            while (! str_contains($child->getOutput(), 'READY') && $child->isRunning() && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertStringContainsString('READY', $child->getOutput(), $child->getErrorOutput());
            if ($stage !== 'committed') {
                $this->artisan('perysee:prune-tracking', ['--limit' => 1])->assertSuccessful();
                $this->assertSame(1, DB::table('events')->count());
            }
        } finally {
            $child->stop(0, 9);
        }
        $lock = 'perysee:aggregate:'.substr(hash('sha256', DB::connection()->getDatabaseName()), 0, 32);
        $deadline = microtime(true) + 5;
        while ((int) DB::selectOne('SELECT IS_FREE_LOCK(?) AS available', [$lock])->available !== 1 && microtime(true) < $deadline) {
            usleep(10000);
        }
        $this->assertSame(1, (int) DB::selectOne('SELECT IS_FREE_LOCK(?) AS available', [$lock])->available, 'MariaDB must release the killed connection before the next scheduled attempt.');
        app(Aggregator::class)->run(1, 15);
        app(Aggregator::class)->run(1, 15);
        $this->assertSame(1, DB::table('events')->whereNotNull('processed_at')->count());
        $this->assertSame(1, (int) DB::table('stats_daily')->sum('pageviews'));
        $this->assertSame(1, (int) DB::table('stats_hourly')->sum('pageviews'));
        $this->assertSame(1, (int) DB::table('stats_daily')->sum('sessions'));
        $this->assertSame(1, DB::table('measured_sessions')->count());
    }

    public function test_timezone_update_waits_for_and_then_respects_a_concurrent_first_collection(): void
    {
        $site = Website::factory()->create(['timezone' => 'UTC']);
        $owner = User::factory()->create();
        $site->members()->attach($owner, ['role' => 'owner']);
        DB::beginTransaction();
        DB::table('websites')->where('id', $site->id)->lockForUpdate()->first();
        DB::table('websites')->where('id', $site->id)->update(['first_collected_at' => now('UTC')]);
        DB::table('events')->insert(['website_id' => $site->id, 'received_at' => now('UTC'), 'path' => '/', 'consent_given' => true]);
        $child = new Process([PHP_BINARY, base_path('tests/Support/update-timezone-child.php'), (string) $site->id, (string) $owner->id, $this->installationDirectory.'/child'], base_path());
        $child->start();
        try {
            $deadline = microtime(true) + 10;
            while (! str_contains($child->getOutput(), 'READY') && $child->isRunning() && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertStringContainsString('READY', $child->getOutput(), $child->getErrorOutput());
            usleep(100000);
            $this->assertTrue($child->isRunning());
            DB::commit();
            $child->wait();
            $result = json_decode(explode("READY\n", $child->getOutput(), 2)[1], true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(422, $result['status']);
            $this->assertArrayHasKey('timezone', $result['body']['errors']);
            $this->assertSame('UTC', $site->fresh()->timezone);
        } finally {
            if (DB::transactionLevel()) {
                DB::rollBack();
            }
            $child->stop(0, 9);
        }
    }

    public function test_database_lock_excludes_another_aggregator_independently_of_cache(): void
    {
        config(['database.connections.competing' => config('database.connections.mariadb')]);
        $other = DB::connection('competing');
        $name = 'perysee:aggregate:'.substr(hash('sha256', DB::connection()->getDatabaseName()), 0, 32);
        $this->assertSame(1, (int) $other->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$name])->acquired);
        try {
            $this->assertSame(0, app(Aggregator::class)->run());
            $this->assertSame(0, DB::table('analytics_state')->where('name', 'aggregate_success')->count());
        } finally {
            $other->selectOne('SELECT RELEASE_LOCK(?) AS released', [$name]);
            DB::purge('competing');
        }
    }

    public function test_an_earlier_id_committed_after_a_later_id_is_still_processed(): void
    {
        $site = Website::factory()->create();
        config(['database.connections.late' => config('database.connections.mariadb')]);
        $late = DB::connection('late');
        $late->beginTransaction();
        try {
            $first = $late->table('events')->insertGetId(['website_id' => $site->id, 'received_at' => '2026-10-24 12:00:00', 'path' => '/earlier', 'consent_given' => true]);
            $second = DB::table('events')->insertGetId(['website_id' => $site->id, 'received_at' => '2026-10-24 12:01:00', 'path' => '/later', 'consent_given' => true]);
            $this->assertLessThan($second, $first);
            $this->assertSame(1, app(Aggregator::class)->run());
            $late->commit();
            $this->assertSame(1, app(Aggregator::class)->run());
            $this->assertNotNull(DB::table('events')->where('id', $first)->value('processed_at'));
            $this->assertDatabaseHas('stats_dimension_daily', ['dimension_value' => '/earlier', 'metric_count' => 1]);
            $this->assertSame(2, (int) DB::table('stats_daily')->sum('pageviews'));
        } finally {
            if ($late->transactionLevel()) {
                $late->rollBack();
            }
            DB::purge('late');
        }
    }
}
