<?php

namespace Tests\Feature;

use App\Analytics\Aggregation\Aggregator;
use App\Analytics\Aggregation\Segments;
use App\Analytics\Reporting\JourneyReports;
use App\Models\Website;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class IdleSchedulerCostTest extends TestCase
{
    use RefreshDatabase;

    private function processedEvent(Website $site, string $time): int
    {
        return DB::table('events')->insertGetId(['website_id' => $site->id, 'received_at' => $time, 'event_type' => 1, 'path' => '/', 'processed_at' => now(), 'consent_given' => true]);
    }

    /**
     * @return array{queries: list<string>, transactions: int}
     */
    private function observe(callable $work): array
    {
        $queries = [];
        $transactions = 0;
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        Event::listen(TransactionBeginning::class, function () use (&$transactions): void {
            $transactions++;
        });
        $work();

        return ['queries' => $queries, 'transactions' => $transactions];
    }

    public function test_a_finished_rebuild_does_not_scan_the_events_again(): void
    {
        $site = Website::factory()->create();
        $this->processedEvent($site, '2026-10-24 10:00:00');
        $this->assertSame(1, app(Segments::class)->rebuild(500));

        $seen = $this->observe(fn () => $this->assertSame(0, app(Segments::class)->rebuild(500)));

        $this->assertSame(0, $seen['transactions']);
        $this->assertLessThanOrEqual(3, count($seen['queries']), implode(' | ', $seen['queries']));
        $this->assertEmpty(array_filter($seen['queries'], fn (string $sql): bool => str_contains($sql, 'segment_events')));
    }

    public function test_events_processed_after_a_finished_rebuild_are_still_rebuilt(): void
    {
        $site = Website::factory()->create();
        $this->processedEvent($site, '2026-10-24 10:00:00');
        app(Segments::class)->rebuild(500);
        $this->processedEvent($site, '2026-10-24 11:00:00');

        $this->assertSame(1, app(Segments::class)->rebuild(500));
        $this->assertSame(2, (int) DB::table('stats_segment_daily')->sum('pageviews'));
        $this->assertSame(0, app(Segments::class)->rebuild(500));
    }

    public function test_events_aggregated_after_a_finished_rebuild_are_not_rebuilt_twice(): void
    {
        $site = Website::factory()->create();
        $this->processedEvent($site, '2026-10-24 10:00:00');
        app(Segments::class)->rebuild(500);
        DB::table('events')->insert(['website_id' => $site->id, 'received_at' => '2026-10-24 12:00:00', 'event_type' => 1, 'path' => '/', 'consent_given' => true]);
        app(Aggregator::class)->run();

        $this->assertSame(0, app(Segments::class)->rebuild(500));
        $this->assertSame(2, (int) DB::table('stats_segment_daily')->sum('pageviews'));
    }

    public function test_an_idle_journey_tick_takes_no_transaction_and_one_read(): void
    {
        $seen = $this->observe(fn () => $this->assertFalse(app(JourneyReports::class)->tick()));

        $this->assertSame(0, $seen['transactions']);
        $this->assertCount(1, $seen['queries'], implode(' | ', $seen['queries']));
    }

    public function test_expired_events_are_pruned_in_one_pass_and_keep_their_segment_counters(): void
    {
        $site = Website::factory()->create(['tracking_mode' => 'sessions']);
        $old = now('UTC')->subDays(100);
        for ($i = 0; $i < 60; $i++) {
            DB::table('events')->insert(['website_id' => $site->id, 'received_at' => $old->copy()->addMinutes($i)->format('Y-m-d H:i:s'), 'processed_at' => now(), 'event_type' => 1, 'path' => '/page-'.($i % 3), 'tracking_mode' => 'sessions', 'session_key' => 'session-'.($i % 20), 'consent_given' => true]);
        }

        $seen = $this->observe(fn () => $this->artisan('perysee:prune-tracking', ['--limit' => 100])->assertSuccessful());

        $sites = array_filter($seen['queries'], fn (string $sql): bool => (bool) preg_match('/from [`"]websites[`"]/i', $sql));
        $segments = array_filter($seen['queries'], fn (string $sql): bool => (bool) preg_match('/segment_(events|sessions)|stats_segment_/', $sql));
        $this->assertLessThanOrEqual(2, count($sites), 'Websites were queried '.count($sites).' times.');
        $this->assertLessThanOrEqual(16, count($segments), 'Segment tables were queried '.count($segments).' times.');
        $this->assertSame(0, DB::table('events')->count());
        $this->assertSame(0, DB::table('segment_events')->count());
        $this->assertSame(60, (int) DB::table('stats_segment_daily')->sum('pageviews'));
        $this->assertSame(20, (int) DB::table('stats_segment_daily')->sum('sessions'));
    }

    public function test_a_scheduler_pass_with_nothing_to_do_stays_within_a_small_query_budget(): void
    {
        Website::factory()->create();
        app(Segments::class)->rebuild(500);

        $seen = $this->observe(function (): void {
            foreach (['perysee:aggregate --batch=500 --budget-seconds=15', 'perysee:maintenance --limit=1000', 'perysee:rebuild-segments --batch=500', 'perysee:journey-reports --batch=500 --budget=10'] as $command) {
                $this->artisan($command)->assertSuccessful();
            }
        });

        // MariaDB adds a lock and an unlock around each command that serialises with the aggregator.
        $budget = in_array(DB::getDriverName(), ['mariadb', 'mysql'], true) ? 40 : 30;

        $this->assertLessThanOrEqual($budget, count($seen['queries']), count($seen['queries']).' queries: '.implode(' | ', $seen['queries']));
    }
}
