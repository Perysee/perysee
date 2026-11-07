<?php

namespace Tests\Feature;

use App\Analytics\Aggregation\Aggregator;
use App\Analytics\Aggregation\Goals;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GoalValueTest extends TestCase
{
    use RefreshDatabase;

    private function site(string $mode = 'sessions'): Website
    {
        return Website::factory()->create(['tracking_mode' => $mode]);
    }

    private function goal(Website $site, string $value = 'purchase', int $snapshot = 0): int
    {
        $id = DB::table('goals')->insertGetId(['website_id' => $site->id, 'name' => $value, 'current_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('goal_versions')->insert(['website_id' => $site->id, 'goal_id' => $id, 'version' => 1, 'type' => 'event', 'value' => $value, 'threshold' => 1, 'snapshot_id' => $snapshot, 'created_at' => now()]);

        return $id;
    }

    private function pageview(Website $site, string $session, string $time, string $source = 'chatgpt', string $country = 'FR'): int
    {
        return DB::table('events')->insertGetId(['website_id' => $site->id, 'received_at' => $time, 'event_type' => 1, 'path' => '/', 'tracking_mode' => 'sessions', 'session_key' => $session, 'country_code' => $country, 'geo_available' => true, 'device_type' => 'mobile', 'dimensions_available' => true, 'source_value' => $source, 'consent_given' => true]);
    }

    private function order(Website $site, ?string $session, string $time, ?int $thousandths, string $name = 'purchase'): int
    {
        return DB::table('events')->insertGetId(['website_id' => $site->id, 'received_at' => $time, 'event_type' => 2, 'event_name' => $name, 'path' => '/thanks', 'tracking_mode' => $session ? 'sessions' : 'pageviews', 'session_key' => $session, 'event_value' => $thousandths, 'consent_given' => true, 'source_value' => 'direct', 'dimensions_available' => true]);
    }

    /**
     * @return array{conversions: int, hits: int, value: int, valued: int}
     */
    private function totals(?string $where = null, mixed $equals = null): array
    {
        $query = DB::table('stats_goal_daily');
        if ($where !== null) {
            $query->where($where, $equals);
        }

        return ['conversions' => (int) (clone $query)->sum('conversions'), 'hits' => (int) (clone $query)->sum('hits'), 'value' => (int) (clone $query)->sum('value_sum'), 'valued' => (int) (clone $query)->sum('valued_hits')];
    }

    public function test_two_orders_of_one_session_are_one_conversion_and_two_valued_hits(): void
    {
        $site = $this->site();
        $this->goal($site);
        $this->pageview($site, 's1', '2026-10-24 10:00:00');
        $this->order($site, 's1', '2026-10-24 10:05:00', 49900);
        $this->order($site, 's1', '2026-10-24 10:09:00', 20100);

        app(Aggregator::class)->run();

        $this->assertSame(['conversions' => 1, 'hits' => 2, 'value' => 70000, 'valued' => 2], $this->totals());
    }

    public function test_hits_without_a_value_are_counted_but_not_valued(): void
    {
        $site = $this->site();
        $this->goal($site);
        $this->pageview($site, 's1', '2026-10-24 10:00:00');
        $this->order($site, 's1', '2026-10-24 10:05:00', null);
        $this->order($site, 's1', '2026-10-24 10:06:00', 0);
        $this->order($site, 's1', '2026-10-24 10:07:00', 5000);

        app(Aggregator::class)->run();

        $this->assertSame(['conversions' => 1, 'hits' => 3, 'value' => 5000, 'valued' => 2], $this->totals());
    }

    public function test_the_value_of_other_events_is_not_added_to_the_goal(): void
    {
        $site = $this->site();
        $this->goal($site);
        $this->pageview($site, 's1', '2026-10-24 10:00:00');
        $this->order($site, 's1', '2026-10-24 10:05:00', 9000, 'upgrade');
        $this->order($site, 's1', '2026-10-24 10:06:00', 1000);

        app(Aggregator::class)->run();

        $this->assertSame(1000, $this->totals()['value']);
    }

    public function test_the_value_follows_the_first_touch_of_the_session(): void
    {
        $site = $this->site();
        $this->goal($site);
        $this->pageview($site, 's1', '2026-10-24 10:00:00', 'chatgpt', 'FR');
        $this->pageview($site, 's2', '2026-10-24 10:00:00', 'google', 'DE');
        $this->order($site, 's1', '2026-10-24 10:05:00', 30000);
        $this->order($site, 's2', '2026-10-24 10:05:00', 10000);
        $this->order($site, 's2', '2026-10-24 10:06:00', 20000);

        app(Aggregator::class)->run();

        $this->assertSame(30000, $this->totals('source', 'chatgpt.com')['value']);
        $this->assertSame(30000, $this->totals('source', 'google')['value']);
        $this->assertSame(1, $this->totals('source', 'chatgpt.com')['valued']);
        $this->assertSame(2, $this->totals('source', 'google')['valued']);
        $this->assertSame(30000, $this->totals('country', 'DE')['value']);
        $this->assertSame(60000, $this->totals()['value']);
    }

    public function test_without_cookies_the_value_is_available_while_the_conversion_is_not(): void
    {
        $site = $this->site('pageviews');
        $this->goal($site);
        $this->order($site, null, '2026-10-24 10:05:00', 12340);
        $this->order($site, null, '2026-10-24 10:06:00', 100);

        app(Aggregator::class)->run();

        $this->assertSame(['conversions' => 0, 'hits' => 2, 'value' => 12440, 'valued' => 2], $this->totals());
    }

    public function test_running_the_aggregator_again_does_not_count_the_value_twice(): void
    {
        $site = $this->site();
        $this->goal($site);
        $this->pageview($site, 's1', '2026-10-24 10:00:00');
        $this->order($site, 's1', '2026-10-24 10:05:00', 7000);

        app(Aggregator::class)->run();
        app(Aggregator::class)->run();

        $this->assertSame(['conversions' => 1, 'hits' => 1, 'value' => 7000, 'valued' => 1], $this->totals());
    }

    public function test_a_late_earlier_event_adds_its_own_value_and_moves_nothing(): void
    {
        $site = $this->site();
        $this->goal($site);
        $this->pageview($site, 's1', '2026-10-24 10:00:00');
        $this->order($site, 's1', '2026-10-25 09:00:00', 40000);
        app(Aggregator::class)->run();

        $this->order($site, 's1', '2026-10-24 10:30:00', 2000);
        app(Aggregator::class)->run();

        $this->assertSame(['conversions' => 1, 'hits' => 2, 'value' => 42000, 'valued' => 2], $this->totals());
        $this->assertSame(40000, (int) DB::table('stats_goal_daily')->where('stat_date', '2026-10-25')->sum('value_sum'));
        $this->assertSame(2000, (int) DB::table('stats_goal_daily')->where('stat_date', '2026-10-24')->sum('value_sum'));
    }

    public function test_the_backfill_of_a_goal_defined_later_counts_the_same_value_as_the_live_count(): void
    {
        $site = $this->site();
        $this->pageview($site, 's1', '2026-10-24 10:00:00');
        $this->pageview($site, 's2', '2026-10-24 10:00:00', 'google');
        $this->order($site, 's1', '2026-10-24 10:05:00', 11000);
        $this->order($site, 's1', '2026-10-24 10:06:00', 22000);
        $this->order($site, 's2', '2026-10-24 10:07:00', 5500);
        app(Aggregator::class)->run();
        $goal = $this->goal($site, 'purchase', (int) DB::table('events')->max('id'));
        app(Goals::class)->queueBackfill($site->id, $goal, 1, (int) DB::table('events')->max('id'));

        for ($i = 0; $i < 5 && app(Goals::class)->rebuild(2) > 0; $i++) {
        }
        $this->order($site, 's2', '2026-10-24 11:00:00', 4500);
        app(Aggregator::class)->run();

        $this->assertSame(['conversions' => 2, 'hits' => 4, 'value' => 43000, 'valued' => 4], $this->totals());
        $this->assertSame(0, app(Goals::class)->rebuild(10));
        app(Aggregator::class)->run();
        $this->assertSame(['conversions' => 2, 'hits' => 4, 'value' => 43000, 'valued' => 4], $this->totals());
    }

    public function test_the_value_columns_cost_no_extra_query(): void
    {
        $site = $this->site();
        $this->goal($site);
        foreach (range(1, 40) as $i) {
            $this->pageview($site, 's'.$i, '2026-10-24 10:00:00');
            $this->order($site, 's'.$i, '2026-10-24 10:05:00', $i * 1000);
        }
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        app(Aggregator::class)->run();

        $goal = array_filter($queries, fn (string $sql): bool => (bool) preg_match('/goal_sessions|stats_goal_daily|goal_versions/', $sql));
        $this->assertLessThanOrEqual(6, count($goal), implode(' | ', $goal));
        $this->assertSame(820000, $this->totals()['value']);
    }
}
