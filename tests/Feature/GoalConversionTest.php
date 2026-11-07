<?php

namespace Tests\Feature;

use App\Analytics\Aggregation\Aggregator;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GoalConversionTest extends TestCase
{
    use RefreshDatabase;

    private function site(string $mode = 'sessions'): Website
    {
        return Website::factory()->create(['tracking_mode' => $mode]);
    }

    private function goal(Website $site, string $type, string $value, int $threshold = 1, string $name = 'Goal', int $snapshot = 0): int
    {
        $id = DB::table('goals')->insertGetId(['website_id' => $site->id, 'name' => $name, 'current_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('goal_versions')->insert(['website_id' => $site->id, 'goal_id' => $id, 'version' => 1, 'type' => $type, 'value' => $value, 'threshold' => $threshold, 'snapshot_id' => $snapshot, 'created_at' => now()]);

        return $id;
    }

    private function pageview(Website $site, string $session, string $time, string $path = '/', array $extra = []): int
    {
        return DB::table('events')->insertGetId($extra + ['website_id' => $site->id, 'received_at' => $time, 'event_type' => 1, 'path' => $path, 'tracking_mode' => 'sessions', 'session_key' => $session, 'consent_given' => true]);
    }

    private function named(Website $site, string $session, string $time, string $name): int
    {
        return DB::table('events')->insertGetId(['website_id' => $site->id, 'received_at' => $time, 'event_type' => 2, 'event_name' => $name, 'path' => '/', 'tracking_mode' => 'sessions', 'session_key' => $session, 'consent_given' => true]);
    }

    public function test_a_session_converts_once_however_many_matching_events(): void
    {
        $site = $this->site();
        $goal = $this->goal($site, 'event', 'signup');
        $this->pageview($site, 's1', '2026-10-24 10:00:00');
        foreach (['10:01:00', '10:02:00', '10:03:00'] as $time) {
            $this->named($site, 's1', '2026-10-24 '.$time, 'signup');
        }
        $this->pageview($site, 's2', '2026-10-24 11:00:00');
        $this->named($site, 's2', '2026-10-24 11:01:00', 'signup');
        $this->named($site, 's2', '2026-10-24 11:02:00', 'other');
        $this->pageview($site, 's3', '2026-10-24 12:00:00');

        app(Aggregator::class)->run();
        app(Aggregator::class)->run();

        $this->assertSame(2, DB::table('goal_sessions')->where('goal_id', $goal)->whereNotNull('converted_at')->count());
        $this->assertSame(2, (int) DB::table('stats_goal_daily')->sum('conversions'));
        $this->assertSame(4, (int) DB::table('stats_goal_daily')->sum('hits'));
        $this->assertSame('2026-10-24 10:01:00', substr(DB::table('goal_sessions')->where('session_key', 's1')->value('converted_at'), 0, 19));
    }

    public function test_an_earlier_late_event_moves_the_conversion_without_counting_it_twice(): void
    {
        $site = $this->site();
        $this->goal($site, 'event', 'signup');
        $this->pageview($site, 's1', '2026-10-24 10:00:00');
        $this->named($site, 's1', '2026-10-25 09:00:00', 'signup');
        app(Aggregator::class)->run();
        $this->assertSame(['2026-10-25'], DB::table('stats_goal_daily')->where('conversions', '>', 0)->pluck('stat_date')->all());

        $this->named($site, 's1', '2026-10-24 10:30:00', 'signup');
        app(Aggregator::class)->run();

        $this->assertSame(1, (int) DB::table('stats_goal_daily')->sum('conversions'));
        $this->assertSame(['2026-10-24'], DB::table('stats_goal_daily')->where('conversions', '!=', 0)->pluck('stat_date')->all());
        $this->assertSame(2, (int) DB::table('goal_sessions')->value('hits'));
    }

    public function test_events_older_than_the_goal_snapshot_are_left_to_the_backfill(): void
    {
        $site = $this->site();
        $this->pageview($site, 's1', '2026-10-24 10:00:00');
        $old = $this->named($site, 's1', '2026-10-24 10:01:00', 'signup');
        $this->goal($site, 'event', 'signup', 1, 'Goal', $old);

        app(Aggregator::class)->run();

        $this->assertSame(0, DB::table('goal_sessions')->count());
    }

    public function test_a_site_without_goal_writes_no_goal_table(): void
    {
        $site = $this->site();
        $this->pageview($site, 's1', '2026-10-24 10:00:00');
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        app(Aggregator::class)->run();

        $this->assertEmpty(array_filter($queries, fn (string $sql): bool => (bool) preg_match('/goal_sessions|stats_goal_daily/', $sql)));
    }

    public function test_path_goals_match_the_normalised_path_exactly(): void
    {
        $site = $this->site();
        $this->goal($site, 'path', '/thanks/');
        $this->pageview($site, 's1', '2026-10-24 10:00:00', '/');
        $this->pageview($site, 's1', '2026-10-24 10:01:00', '/thanks/');
        $this->pageview($site, 's2', '2026-10-24 10:00:00', '/thanks/extra');
        $this->named($site, 's3', '2026-10-24 10:00:00', '/thanks/');

        app(Aggregator::class)->run();

        $this->assertSame(['s1'], DB::table('goal_sessions')->pluck('session_key')->all());
    }

    public function test_threshold_converts_on_the_third_hit_only(): void
    {
        $site = $this->site();
        $this->goal($site, 'path', '/article', 3);
        foreach (['10:00:00', '10:05:00'] as $time) {
            $this->pageview($site, 's1', '2026-10-24 '.$time, '/article');
        }
        $this->pageview($site, 's2', '2026-10-24 10:00:00', '/article');
        app(Aggregator::class)->run();
        $this->assertSame(0, (int) DB::table('stats_goal_daily')->sum('conversions'));
        $this->assertSame(3, (int) DB::table('stats_goal_daily')->sum('hits'));

        $this->pageview($site, 's1', '2026-10-24 10:10:00', '/article');
        $this->pageview($site, 's1', '2026-10-24 10:20:00', '/article');
        app(Aggregator::class)->run();

        $this->assertSame(1, (int) DB::table('stats_goal_daily')->sum('conversions'));
        $row = DB::table('goal_sessions')->where('session_key', 's1')->first();
        $this->assertSame(4, (int) $row->hits);
        $this->assertSame('2026-10-24 10:10:00', substr($row->converted_at, 0, 19));
        $this->assertNull(DB::table('goal_sessions')->where('session_key', 's2')->value('converted_at'));
    }

    public function test_sites_without_sessions_only_record_goal_hits(): void
    {
        $site = $this->site('pageviews');
        $this->goal($site, 'path', '/thanks');
        DB::table('events')->insert(['website_id' => $site->id, 'received_at' => '2026-10-24 10:00:00', 'event_type' => 1, 'path' => '/thanks', 'tracking_mode' => 'pageviews', 'consent_given' => true]);

        app(Aggregator::class)->run();

        $this->assertSame(0, DB::table('goal_sessions')->count());
        $this->assertSame(0, (int) DB::table('stats_goal_daily')->sum('conversions'));
        $this->assertSame(1, (int) DB::table('stats_goal_daily')->sum('hits'));
    }
}
