<?php

namespace Tests\Feature;

use App\Analytics\Aggregation\Aggregator;
use App\Installation\InstallationState;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GoalValueReportingTest extends TestCase
{
    use RefreshDatabase;

    private Website $site;

    private User $owner;

    private int $goal;

    protected function setUp(): void
    {
        parent::setUp();
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
        $this->travelTo(now('UTC')->setDate(2026, 10, 26)->setTime(12, 0));
        $this->site = Website::factory()->create(['tracking_mode' => 'sessions']);
        $this->owner = User::factory()->create();
        $this->site->members()->attach($this->owner, ['role' => 'owner']);
        $this->goal = DB::table('goals')->insertGetId(['website_id' => $this->site->id, 'name' => 'Purchase', 'current_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('goal_versions')->insert(['website_id' => $this->site->id, 'goal_id' => $this->goal, 'version' => 1, 'type' => 'event', 'value' => 'purchase', 'threshold' => 1, 'created_at' => now('UTC')->subDays(30)]);
    }

    private function pageview(string $session, string $source, string $country = 'FR'): void
    {
        DB::table('events')->insert(['website_id' => $this->site->id, 'received_at' => '2026-10-24 10:00:00', 'event_type' => 1, 'path' => '/', 'tracking_mode' => 'sessions', 'session_key' => $session, 'country_code' => $country, 'geo_available' => true, 'device_type' => 'mobile', 'dimensions_available' => true, 'source_value' => $source, 'consent_given' => true]);
    }

    private function purchase(string $session, int $thousandths, string $time = '10:05:00', string $day = '2026-10-24'): void
    {
        DB::table('events')->insert(['website_id' => $this->site->id, 'received_at' => $day.' '.$time, 'event_type' => 2, 'event_name' => 'purchase', 'path' => '/thanks', 'tracking_mode' => 'sessions', 'session_key' => $session, 'event_value' => $thousandths, 'consent_given' => true]);
    }

    private function report(): array
    {
        return $this->actingAs($this->owner)->getJson('/api/sites/'.$this->site->id.'/goals/'.$this->goal.'?from=2026-10-24&to=2026-10-26')->assertOk()->json();
    }

    private function seedOrders(): void
    {
        $this->pageview('s1', 'chatgpt', 'FR');
        $this->pageview('s2', 'google', 'DE');
        $this->purchase('s1', 49900);
        $this->purchase('s1', 20100, '10:09:00');
        $this->purchase('s2', 30000, '11:00:00', '2026-10-25');
        app(Aggregator::class)->run();
    }

    public function test_the_report_gives_the_total_the_average_basket_and_the_site_currency(): void
    {
        $this->site->update(['currency' => 'EUR']);
        $this->seedOrders();

        $report = $this->report();

        $this->assertEquals(['currency' => 'EUR', 'total' => 100.0, 'valued_hits' => 3, 'average' => 33.33], $report['amounts']);
        $this->assertSame('EUR', $report['amounts']['currency']);
        $this->assertSame(['conversions' => 2, 'hits' => 3, 'sessions' => 2, 'rate' => 100], $report['totals'], 'The totals keep their previous shape.');
    }

    public function test_the_value_is_broken_down_like_the_conversions(): void
    {
        $this->seedOrders();

        $report = $this->report();

        $sources = collect($report['breakdown']['source'])->keyBy('value');
        $this->assertEquals([70.0, 2, 35.0], [$sources['chatgpt.com']['amount'], $sources['chatgpt.com']['valued_hits'], $sources['chatgpt.com']['average']]);
        $this->assertEquals([30.0, 1, 30.0], [$sources['google']['amount'], $sources['google']['valued_hits'], $sources['google']['average']]);
        $countries = collect($report['breakdown']['country'])->keyBy('value');
        $this->assertEquals(70.0, $countries['FR']['amount']);
        $this->assertEquals(30.0, $countries['DE']['amount']);
        $day = collect($report['series'])->keyBy('date');
        $this->assertEquals([70.0, 30.0, 0.0], [$day['2026-10-24']['amount'], $day['2026-10-25']['amount'], $day['2026-10-26']['amount']]);
    }

    public function test_a_goal_without_value_has_no_average(): void
    {
        $this->pageview('s1', 'google');
        DB::table('events')->insert(['website_id' => $this->site->id, 'received_at' => '2026-10-24 10:05:00', 'event_type' => 2, 'event_name' => 'purchase', 'path' => '/', 'tracking_mode' => 'sessions', 'session_key' => 's1', 'consent_given' => true]);
        app(Aggregator::class)->run();

        $report = $this->report();

        $this->assertEquals(['currency' => null, 'total' => 0, 'valued_hits' => 0, 'average' => null], $report['amounts']);
        $this->assertSame(1, $report['totals']['hits']);
    }

    public function test_amounts_follow_the_filters_of_the_report(): void
    {
        $this->seedOrders();

        $json = $this->actingAs($this->owner)->getJson('/api/sites/'.$this->site->id.'/goals/'.$this->goal.'?from=2026-10-24&to=2026-10-26&filters[country][]=DE')->assertOk()->json();

        $this->assertEquals(30.0, (float) $json['amounts']['total']);
    }

    public function test_a_viewer_reads_the_amounts_and_a_stranger_does_not(): void
    {
        $this->seedOrders();
        $viewer = User::factory()->create();
        $this->site->members()->attach($viewer, ['role' => 'viewer']);
        $stranger = User::factory()->create();

        $this->actingAs($viewer)->getJson('/api/sites/'.$this->site->id.'/goals/'.$this->goal.'?from=2026-10-24&to=2026-10-26')->assertOk()->assertJsonPath('amounts.total', 100);
        $this->actingAs($stranger)->getJson('/api/sites/'.$this->site->id.'/goals/'.$this->goal.'?from=2026-10-24&to=2026-10-26')->assertForbidden();
    }

    public function test_the_csv_keeps_its_columns_without_values_and_gains_two_with_values(): void
    {
        $this->pageview('s0', 'google');
        DB::table('events')->insert(['website_id' => $this->site->id, 'received_at' => '2026-10-24 10:05:00', 'event_type' => 2, 'event_name' => 'purchase', 'path' => '/', 'tracking_mode' => 'sessions', 'session_key' => 's0', 'consent_given' => true]);
        app(Aggregator::class)->run();
        $query = '?from=2026-10-24&to=2026-10-26';
        $plain = $this->actingAs($this->owner)->get('/sites/'.$this->site->id.'/goals/'.$this->goal.'/export'.$query)->assertOk()->streamedContent();
        $this->assertStringStartsWith("dimension,value,conversions,sessions,rate_percent\n", $plain);

        $this->seedOrders();
        $csv = $this->actingAs($this->owner)->get('/sites/'.$this->site->id.'/goals/'.$this->goal.'/export'.$query)->assertOk()->streamedContent();

        $rows = array_map('str_getcsv', array_filter(explode("\n", $csv)));
        $this->assertSame(['dimension', 'value', 'conversions', 'sessions', 'rate_percent', 'value_total', 'value_average'], $rows[0]);
        $this->assertSame('100', $rows[1][5]);
        $this->assertSame('33.33', $rows[1][6]);
    }
}
