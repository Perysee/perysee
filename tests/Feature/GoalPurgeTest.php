<?php

namespace Tests\Feature;

use App\Analytics\Aggregation\Aggregator;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GoalPurgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_goal_sessions_older_than_the_retention_are_purged_but_counters_are_kept(): void
    {
        $site = Website::factory()->create(['tracking_mode' => 'sessions']);
        $id = DB::table('goals')->insertGetId(['website_id' => $site->id, 'name' => 'Signup', 'current_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('goal_versions')->insert(['website_id' => $site->id, 'goal_id' => $id, 'version' => 1, 'type' => 'event', 'value' => 'signup', 'threshold' => 1, 'created_at' => now()]);
        foreach ([['old', now('UTC')->subDays(100)], ['recent', now('UTC')->subDay()]] as [$session, $time]) {
            DB::table('events')->insert(['website_id' => $site->id, 'received_at' => $time->format('Y-m-d H:i:s'), 'event_type' => 2, 'event_name' => 'signup', 'path' => '/', 'tracking_mode' => 'sessions', 'session_key' => $session, 'consent_given' => true]);
        }
        app(Aggregator::class)->run();
        $this->assertSame(2, DB::table('goal_sessions')->count());

        $this->artisan('perysee:prune-tracking', ['--limit' => 100])->assertSuccessful();

        $this->assertSame(['recent'], DB::table('goal_sessions')->pluck('session_key')->all());
        $this->assertSame(2, (int) DB::table('stats_goal_daily')->sum('conversions'));
    }

    public function test_goal_counters_older_than_the_statistics_retention_are_purged_like_the_other_statistics(): void
    {
        $site = Website::factory()->create(['tracking_mode' => 'sessions']);
        foreach ([1200, 10] as $days) {
            $day = now('UTC')->subDays($days)->startOfDay();
            DB::table('stats_goal_daily')->insert([
                'website_id' => $site->id, 'goal_id' => 1, 'version' => 1, 'stat_date' => $day->toDateString(), 'bucket_start' => $day->format('Y-m-d H:i:s'),
                'segment_hash' => str_repeat('a', 64), 'dimensions' => '{}', 'conversions' => 1, 'hits' => 1,
            ]);
        }

        $this->artisan('perysee:maintenance', ['--limit' => 100])->assertSuccessful();

        $this->assertSame([now('UTC')->subDays(10)->toDateString()], DB::table('stats_goal_daily')->pluck('stat_date')->map(fn ($date) => substr((string) $date, 0, 10))->all());
    }

    public function test_deleting_a_site_removes_its_goal_data(): void
    {
        $site = Website::factory()->create(['tracking_mode' => 'sessions']);
        $id = DB::table('goals')->insertGetId(['website_id' => $site->id, 'name' => 'Signup', 'current_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('goal_versions')->insert(['website_id' => $site->id, 'goal_id' => $id, 'version' => 1, 'type' => 'event', 'value' => 'signup', 'threshold' => 1, 'created_at' => now()]);
        DB::table('events')->insert(['website_id' => $site->id, 'received_at' => now('UTC')->subDay()->format('Y-m-d H:i:s'), 'event_type' => 2, 'event_name' => 'signup', 'path' => '/', 'tracking_mode' => 'sessions', 'session_key' => 'a', 'consent_given' => true]);
        app(Aggregator::class)->run();
        DB::table('websites')->where('id', $site->id)->update(['deleting_at' => now()]);

        for ($i = 0; $i < 30; $i++) {
            $this->artisan('perysee:maintenance', ['--limit' => 100])->assertSuccessful();
        }

        foreach (['goals', 'goal_versions', 'goal_sessions', 'stats_goal_daily'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
    }
}
