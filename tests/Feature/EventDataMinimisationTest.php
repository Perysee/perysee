<?php

namespace Tests\Feature;

use App\Analytics\Aggregation\Aggregator;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EventDataMinimisationTest extends TestCase
{
    use RefreshDatabase;

    private Website $site;

    private int $goal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Website::factory()->create(['tracking_mode' => 'sessions']);
        $this->goal = DB::table('goals')->insertGetId(['website_id' => $this->site->id, 'name' => 'Goal', 'current_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('goal_versions')->insert(['website_id' => $this->site->id, 'goal_id' => $this->goal, 'version' => 1, 'type' => 'event', 'value' => 'purchase', 'threshold' => 1, 'snapshot_id' => 0, 'created_at' => now()]);
    }

    private function event(string $time, int $type, ?string $name = null, ?array $props = null, ?int $value = null): int
    {
        return DB::table('events')->insertGetId(['website_id' => $this->site->id, 'received_at' => $time, 'event_type' => $type, 'event_name' => $name, 'path' => '/', 'tracking_mode' => 'sessions', 'session_key' => 's1', 'consent_given' => true, 'event_value' => $value, 'event_props' => $props === null ? null : json_encode($props)]);
    }

    public function test_the_first_event_kept_for_a_session_carries_no_properties_or_value(): void
    {
        // A custom first event is not stored as a session start; the pageview is. Both carry properties in this test.
        DB::table('events')->insert(['website_id' => $this->site->id, 'received_at' => '2026-10-24 10:00:00', 'event_type' => 1, 'path' => '/', 'tracking_mode' => 'sessions', 'session_key' => 's1', 'consent_given' => true, 'event_value' => 4200, 'event_props' => json_encode(['order' => 'secret-order-77'])]);
        $this->event('2026-10-24 10:01:00', 2, 'purchase', ['order' => 'secret-order-77'], 4200);

        app(Aggregator::class)->run();

        $session = DB::table('segment_sessions')->where('website_id', $this->site->id)->first();
        $goal = DB::table('goal_sessions')->where('website_id', $this->site->id)->first();
        $this->assertNotNull($session);
        $this->assertNotNull($goal);
        $this->assertNotNull($goal->converted_at);
        foreach ([$session->event_data, $goal->event_data] as $data) {
            $this->assertStringNotContainsString('secret-order-77', $data);
            $this->assertArrayNotHasKey('event_props', json_decode($data, true));
            $this->assertArrayNotHasKey('event_value', json_decode($data, true));
            $this->assertArrayNotHasKey('session_key', json_decode($data, true));
        }
        $this->assertSame(1, (int) DB::table('stats_goal_daily')->sum('conversions'));
        $this->assertSame(4200, (int) DB::table('stats_goal_daily')->sum('value_sum'));
    }

    public function test_a_row_written_before_is_not_rewritten_when_nothing_changes(): void
    {
        $this->event('2026-10-24 10:00:00', 1);
        $this->event('2026-10-24 10:01:00', 2, 'purchase');
        app(Aggregator::class)->run();
        $legacy = json_encode(array_merge(json_decode(DB::table('goal_sessions')->value('event_data'), true), ['event_props' => '{"a":"b"}', 'event_value' => 5, 'session_key' => 's1']));
        DB::table('goal_sessions')->update(['event_data' => $legacy]);

        // An event of the same session that is not a hit touches the row without changing it.
        $this->event('2026-10-24 10:02:00', 1);
        app(Aggregator::class)->run();

        $this->assertSame($legacy, DB::table('goal_sessions')->value('event_data'));
        $this->assertSame(1, (int) DB::table('stats_goal_daily')->sum('conversions'));
    }
}
