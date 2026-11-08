<?php

namespace Tests\Feature;

use App\Analytics\Aggregation\Aggregator;
use App\Analytics\Reporting\Reports;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AggregationQueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    private function seedEvents(Website $site, int $count, int $sessions): void
    {
        for ($i = 0; $i < $count; $i++) {
            DB::table('events')->insert(['website_id' => $site->id, 'received_at' => sprintf('2026-10-24 10:%02d:00', $i % 60), 'event_type' => 1, 'path' => '/page-'.($i % 3), 'country_code' => 'FR', 'geo_available' => true, 'device_type' => 'mobile', 'source_value' => 'chatgpt', 'dimensions_available' => true, 'tracking_mode' => 'sessions', 'session_key' => 'session-'.($i % $sessions), 'consent_given' => true]);
        }
    }

    /**
     * @return list<string>
     */
    private function queriesDuring(callable $work): array
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $work();

        return $queries;
    }

    public function test_segment_counters_are_written_once_per_batch_whatever_the_number_of_events(): void
    {
        $site = Website::factory()->create(['tracking_mode' => 'sessions']);
        $this->seedEvents($site, 60, 20);

        $queries = $this->queriesDuring(fn () => app(Aggregator::class)->run());

        $segment = array_filter($queries, fn (string $sql): bool => (bool) preg_match('/segment_(events|sessions)|stats_segment_/', $sql));
        $this->assertLessThanOrEqual(12, count($segment), 'Segment tables were queried '.count($segment).' times for 60 events.');
    }

    public function test_batched_segment_counters_match_the_events(): void
    {
        $site = Website::factory()->create(['tracking_mode' => 'sessions']);
        $this->seedEvents($site, 60, 20);

        app(Aggregator::class)->run();

        foreach (['stats_segment_daily', 'stats_segment_hourly'] as $table) {
            $this->assertSame(60, (int) DB::table($table)->sum('pageviews'), $table);
            $this->assertSame(20, (int) DB::table($table)->sum('sessions'), $table);
        }
        $this->assertSame(60, DB::table('segment_events')->count());
        $this->assertSame(20, DB::table('segment_sessions')->count());
    }

    public function test_a_second_run_does_not_count_the_same_events_twice(): void
    {
        $site = Website::factory()->create(['tracking_mode' => 'sessions']);
        $this->seedEvents($site, 12, 4);

        app(Aggregator::class)->run();
        app(Aggregator::class)->run();

        $this->assertSame(12, (int) DB::table('stats_segment_daily')->sum('pageviews'));
        $this->assertSame(4, (int) DB::table('stats_segment_daily')->sum('sessions'));
    }

    public function test_session_and_dimension_tables_are_written_once_per_batch(): void
    {
        $site = Website::factory()->create(['tracking_mode' => 'sessions']);
        $this->seedEvents($site, 60, 20);

        $queries = $this->queriesDuring(fn () => app(Aggregator::class)->run());

        $others = array_filter($queries, fn (string $sql): bool => (bool) preg_match('/measured_sessions|stats_dimension_daily/', $sql));
        $this->assertLessThanOrEqual(8, count($others), 'Session and dimension tables were queried '.count($others).' times for 60 events.');
        $this->assertSame(20, DB::table('measured_sessions')->count());
        $this->assertSame(60, (int) DB::table('stats_dimension_daily')->where('dimension_type', 'country')->sum('metric_count'));
        $this->assertSame(60, (int) DB::table('stats_dimension_daily')->where('dimension_type', 'path')->sum('metric_count'));
    }

    public function test_events_out_of_order_inside_a_batch_keep_the_earliest_and_latest_session_times(): void
    {
        $site = Website::factory()->create(['tracking_mode' => 'sessions']);
        foreach (['2026-10-24 12:00:00', '2026-10-24 10:00:00', '2026-10-24 11:00:00'] as $time) {
            DB::table('events')->insert(['website_id' => $site->id, 'received_at' => $time, 'event_type' => 1, 'path' => '/', 'tracking_mode' => 'sessions', 'session_key' => 'same', 'consent_given' => true]);
        }

        app(Aggregator::class)->run();

        $session = DB::table('measured_sessions')->where('session_key', 'same')->first();
        $this->assertSame('2026-10-24 10:00:00', substr($session->first_seen_at, 0, 19));
        $this->assertSame('2026-10-24 12:00:00', substr($session->last_seen_at, 0, 19));
        $this->assertSame(1, (int) DB::table('stats_daily')->sum('sessions'));
        $this->assertSame(1, (int) DB::table('stats_hourly')->where('bucket_start', '2026-10-24 10:00:00')->sum('sessions'));
        $this->assertSame(0, (int) DB::table('stats_hourly')->where('bucket_start', '2026-10-24 12:00:00')->sum('sessions'));
    }

    public function test_a_dimension_report_reads_the_statistics_in_three_queries_at_most(): void
    {
        $site = Website::factory()->create();
        foreach (['source' => ['ChatGPT', 'chatgpt', 'google'], 'country' => ['FR', 'US']] as $type => $values) {
            foreach ($values as $value) {
                DB::table('stats_dimension_daily')->insert(['website_id' => $site->id, 'stat_date' => '2026-10-24', 'dimension_type' => $type, 'dimension_hash' => hash('sha256', $type.$value), 'dimension_value' => $value, 'metric_count' => 2]);
            }
        }
        $period = ['from' => '2026-10-20', 'to' => '2026-10-26', 'filters' => [], 'source_group' => 'all', 'provider' => null];

        foreach (['source', 'country'] as $type) {
            $queries = $this->queriesDuring(fn () => app(Reports::class)->dimensionReport($site, $period, $type));

            $this->assertLessThanOrEqual(3, count($queries), $type.': '.implode(' | ', $queries));
        }
    }
}
