<?php

namespace Tests\Feature;

use App\Analytics\Aggregation\Aggregator;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GoalCostTest extends TestCase
{
    use RefreshDatabase;

    private function seedEvents(Website $site, int $count, int $sessions): void
    {
        for ($i = 0; $i < $count; $i++) {
            DB::table('events')->insert(['website_id' => $site->id, 'received_at' => sprintf('2026-10-24 10:%02d:00', $i % 60), 'event_type' => $i % 2 ? 2 : 1, 'event_name' => $i % 2 ? 'signup' : null, 'path' => '/page-'.($i % 3), 'country_code' => 'FR', 'geo_available' => true, 'device_type' => 'mobile', 'source_value' => 'chatgpt', 'dimensions_available' => true, 'tracking_mode' => 'sessions', 'session_key' => 'session-'.($i % $sessions), 'consent_given' => true]);
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

    public function test_goal_tables_are_written_once_per_batch(): void
    {
        $site = Website::factory()->create(['tracking_mode' => 'sessions']);
        foreach (['event' => 'signup', 'path' => '/page-1'] as $type => $value) {
            $id = DB::table('goals')->insertGetId(['website_id' => $site->id, 'name' => $value, 'current_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('goal_versions')->insert(['website_id' => $site->id, 'goal_id' => $id, 'version' => 1, 'type' => $type, 'value' => $value, 'threshold' => 1, 'created_at' => now()]);
        }
        $this->seedEvents($site, 120, 40);

        $queries = $this->queriesDuring(fn () => app(Aggregator::class)->run());

        $goal = array_filter($queries, fn (string $sql): bool => (bool) preg_match('/goal_sessions|stats_goal_daily|goal_versions/', $sql));
        $this->assertLessThanOrEqual(6, count($goal), 'Goal tables were queried '.count($goal).' times for 120 events: '.implode(' | ', $goal));
        $this->assertSame(20, DB::table('goal_sessions')->where('goal_id', DB::table('goal_versions')->where('value', 'signup')->value('goal_id'))->whereNotNull('converted_at')->count());
    }

    public function test_a_site_without_goal_costs_one_definition_read(): void
    {
        $site = Website::factory()->create(['tracking_mode' => 'sessions']);
        $this->seedEvents($site, 60, 20);

        $queries = $this->queriesDuring(fn () => app(Aggregator::class)->run());

        $goal = array_filter($queries, fn (string $sql): bool => (bool) preg_match('/goal/', $sql));
        $this->assertCount(1, $goal, implode(' | ', $goal));
        $this->assertSame(0, DB::table('goal_sessions')->count());
    }

    public function test_an_idle_goal_scheduler_pass_costs_one_read_and_no_transaction(): void
    {
        Website::factory()->create();

        $queries = $this->queriesDuring(fn () => $this->artisan('perysee:rebuild-goals --batch=500')->assertSuccessful());

        $this->assertCount(1, $queries, implode(' | ', $queries));
    }
}
