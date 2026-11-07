<?php

namespace Tests\Feature;

use App\Analytics\Aggregation\Aggregator;
use App\Analytics\Aggregation\Goals;
use App\Installation\InstallationState;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GoalBackfillTest extends TestCase
{
    use RefreshDatabase;

    private Website $site;

    protected function setUp(): void
    {
        parent::setUp();
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
        $this->site = Website::factory()->create(['tracking_mode' => 'sessions']);
        $owner = User::factory()->create();
        $this->site->members()->attach($owner, ['role' => 'owner']);
        $this->actingAs($owner);
    }

    private function event(string $session, string $time, int $type = 1, string $country = 'FR'): int
    {
        return DB::table('events')->insertGetId(['website_id' => $this->site->id, 'received_at' => '2026-10-24 '.$time, 'event_type' => $type, 'event_name' => $type === 2 ? 'signup' : null, 'path' => '/', 'tracking_mode' => 'sessions', 'session_key' => $session,
            'country_code' => $country, 'geo_available' => true, 'device_type' => 'mobile', 'dimensions_available' => true, 'source_value' => 'chatgpt', 'consent_given' => true]);
    }

    private function define(): void
    {
        $this->post('/sites/'.$this->site->id.'/goals', ['name' => 'Signup', 'type' => 'event', 'value' => 'signup'])->assertRedirect();
    }

    private function drain(int $limit = 500): int
    {
        $total = 0;
        for ($i = 0; $i < 20 && ($done = app(Goals::class)->rebuild($limit)) > 0; $i++) {
            $total += $done;
        }

        return $total;
    }

    public function test_backfill_from_retained_events_is_idempotent_and_resumable(): void
    {
        foreach (['s1', 's2', 's3', 's4'] as $i => $session) {
            $this->event($session, '10:0'.$i.':00', 1, $i % 2 ? 'DE' : 'FR');
        }
        foreach (['s1', 's2', 's3'] as $i => $session) {
            $this->event($session, '11:0'.$i.':00', 2);
        }
        $this->event('s1', '11:30:00', 2);
        app(Aggregator::class)->run();
        $this->define();
        $this->assertSame(0, (int) DB::table('stats_goal_daily')->sum('conversions'));

        $this->assertSame(2, app(Goals::class)->rebuild(2));
        $this->assertTrue(app(Goals::class)->backfilling(1, 1));
        $this->assertSame(2, app(Goals::class)->rebuild(2));
        $this->assertSame(0, app(Goals::class)->rebuild(2));
        $this->assertFalse(app(Goals::class)->backfilling(1, 1));
        $this->assertSame(0, app(Goals::class)->rebuild(2));

        $this->assertSame(3, (int) DB::table('stats_goal_daily')->sum('conversions'));
        $this->assertSame(4, (int) DB::table('stats_goal_daily')->sum('hits'));
        $this->assertSame(3, DB::table('goal_sessions')->whereNotNull('converted_at')->count());
        $countries = DB::table('stats_goal_daily')->where('conversions', '>', 0)->selectRaw('country, SUM(conversions) AS n')->groupBy('country')->pluck('n', 'country')->all();
        $this->assertEquals(['FR' => 2, 'DE' => 1], $countries);
    }

    public function test_goals_do_not_double_count_after_rerun(): void
    {
        $this->event('s1', '10:00:00');
        $this->event('s1', '10:05:00', 2);
        // Not aggregated yet when the goal is defined: the backfill counts it and the aggregator must not.
        $this->define();
        $this->event('s2', '12:00:00');
        $this->event('s2', '12:05:00', 2);

        $this->drain();
        app(Aggregator::class)->run();
        $this->drain();
        app(Aggregator::class)->run();

        $this->assertSame(2, (int) DB::table('stats_goal_daily')->sum('conversions'));
        $this->assertSame(2, (int) DB::table('stats_goal_daily')->sum('hits'));
        $this->assertSame([1, 1], DB::table('goal_sessions')->orderBy('session_key')->pluck('hits')->map(fn ($n) => (int) $n)->all());
    }

    public function test_a_session_split_by_the_snapshot_converts_once(): void
    {
        $this->event('s1', '10:00:00');
        $this->event('s1', '10:05:00', 2);
        app(Aggregator::class)->run();
        $this->define();
        $this->event('s1', '10:10:00', 2);
        app(Aggregator::class)->run();
        $this->drain();

        $this->assertSame(1, (int) DB::table('stats_goal_daily')->sum('conversions'));
        $this->assertSame(2, (int) DB::table('goal_sessions')->value('hits'));
    }

    public function test_editing_a_goal_counts_the_new_version_over_past_events(): void
    {
        $this->event('s1', '10:00:00');
        $this->event('s1', '10:05:00', 2);
        $this->event('s1', '10:06:00');
        app(Aggregator::class)->run();
        $this->define();
        $this->drain();
        $this->put('/sites/'.$this->site->id.'/goals/1', ['name' => 'Signup', 'type' => 'path', 'value' => '/', 'threshold' => 2])->assertRedirect();
        $this->drain();

        $this->assertSame([1, 1], [(int) DB::table('stats_goal_daily')->where('version', 1)->sum('conversions'), (int) DB::table('stats_goal_daily')->where('version', 2)->sum('conversions')]);
    }

    public function test_an_idle_backfill_pass_costs_one_read(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->assertSame(0, app(Goals::class)->rebuild());

        $this->assertCount(1, $queries, implode(' | ', $queries));
    }

    public function test_a_job_of_an_archived_goal_is_dropped(): void
    {
        $this->event('s1', '10:05:00', 2);
        $this->define();
        $this->delete('/sites/'.$this->site->id.'/goals/1');

        $this->assertSame(0, app(Goals::class)->rebuild());
        $this->assertFalse(app(Goals::class)->backfilling(1, 1));
        $this->assertSame(0, DB::table('goal_sessions')->count());
    }
}
