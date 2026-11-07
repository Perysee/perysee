<?php

namespace Tests\Feature;

use App\Analytics\Aggregation\Aggregator;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Tests\TestCase;

class EventValuePurgeTest extends TestCase
{
    use RefreshDatabase;

    private function property(Website $site, string $date, string $value = 'card'): void
    {
        DB::table('stats_event_property_daily')->insert(['website_id' => $site->id, 'stat_date' => $date, 'event_name' => 'purchase', 'prop_key' => 'payment', 'value_hash' => hash('sha256', $value), 'prop_value' => $value, 'metric_count' => 1, 'value_sum' => 0, 'valued_count' => 0]);
    }

    private function order(Website $site, string $hash, \DateTimeInterface $at): void
    {
        DB::table('event_order_ids')->insert(['website_id' => $site->id, 'order_hash' => $hash, 'created_at' => $at->format('Y-m-d H:i:s')]);
    }

    public function test_property_statistics_follow_the_statistics_retention(): void
    {
        $site = Website::factory()->create();
        $this->property($site, now('UTC')->subDays(1200)->toDateString());
        $this->property($site, now('UTC')->subDays(1200)->toDateString(), 'paypal');
        $this->property($site, now('UTC')->subDays(10)->toDateString());

        $this->artisan('perysee:prune-event-values')->assertSuccessful();

        $this->assertSame([now('UTC')->subDays(10)->toDateString()], DB::table('stats_event_property_daily')->pluck('stat_date')->map(fn ($date) => substr((string) $date, 0, 10))->all());
    }

    public function test_property_statistics_are_kept_while_older_events_wait_to_be_aggregated(): void
    {
        $site = Website::factory()->create();
        $this->property($site, now('UTC')->subDays(1200)->toDateString());
        DB::table('events')->insert(['website_id' => $site->id, 'received_at' => now('UTC')->subDays(1300)->format('Y-m-d H:i:s'), 'event_type' => 2, 'event_name' => 'purchase', 'path' => '/', 'consent_given' => true]);

        $this->artisan('perysee:prune-event-values')->assertSuccessful();

        $this->assertSame(1, DB::table('stats_event_property_daily')->count());
    }

    public function test_order_identifiers_expire_after_the_dedup_window(): void
    {
        $site = Website::factory()->create();
        $this->order($site, str_repeat('a', 32), now('UTC')->subDays(31));
        $this->order($site, str_repeat('b', 32), now('UTC')->subDays(2));
        $other = Website::factory()->create();
        $this->order($other, str_repeat('a', 32), now('UTC')->subDays(40));

        $this->artisan('perysee:prune-event-values')->assertSuccessful();

        $this->assertSame([str_repeat('b', 32)], DB::table('event_order_ids')->pluck('order_hash')->all());
    }

    public function test_the_window_is_a_setting(): void
    {
        $site = Website::factory()->create();
        $this->order($site, str_repeat('a', 32), now('UTC')->subDays(5));
        config(['perysee.order_dedup_days' => 3]);

        $this->artisan('perysee:prune-event-values')->assertSuccessful();

        $this->assertSame(0, DB::table('event_order_ids')->count());
    }

    public function test_a_limit_bounds_the_work_of_one_run(): void
    {
        $site = Website::factory()->create();
        foreach (range(1, 5) as $i) {
            $this->order($site, str_repeat((string) $i, 32), now('UTC')->subDays(40));
        }

        $this->artisan('perysee:prune-event-values', ['--limit' => 2])->assertSuccessful();
        $this->assertSame(3, DB::table('event_order_ids')->count());
        $this->artisan('perysee:prune-event-values', ['--limit' => 2])->assertSuccessful();
        $this->artisan('perysee:prune-event-values', ['--limit' => 2])->assertSuccessful();
        $this->assertSame(0, DB::table('event_order_ids')->count());
        $this->artisan('perysee:prune-event-values', ['--limit' => 0])->assertFailed();
    }

    public function test_an_idle_run_costs_two_queries_and_a_regular_pass_none(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->artisan('perysee:prune-event-values')->assertSuccessful();

        $this->assertLessThanOrEqual(4, count($queries), implode(' | ', $queries));
        $queries = [];
        $this->artisan('perysee:maintenance')->assertSuccessful();
        $this->assertEmpty(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'stats_event_property_daily') || str_contains($sql, 'event_order_ids')));
    }

    public function test_the_purge_runs_hourly_and_never_overlaps(): void
    {
        $event = collect(Schedule::events())->first(fn ($event): bool => str_contains((string) $event->command, 'perysee:prune-event-values'));

        $this->assertNotNull($event);
        $this->assertSame('0 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_deleting_a_site_removes_its_properties_and_order_identifiers(): void
    {
        $site = Website::factory()->create();
        $other = Website::factory()->create();
        DB::table('events')->insert(['website_id' => $site->id, 'received_at' => now('UTC')->subDay()->format('Y-m-d H:i:s'), 'event_type' => 2, 'event_name' => 'purchase', 'path' => '/', 'event_props' => '{"payment":"card"}', 'consent_given' => true]);
        app(Aggregator::class)->run();
        $this->order($site, str_repeat('a', 32), now('UTC'));
        $this->order($other, str_repeat('a', 32), now('UTC'));
        $this->property($other, now('UTC')->toDateString());
        $this->assertSame(1, DB::table('stats_event_property_daily')->where('website_id', $site->id)->count());
        DB::table('websites')->where('id', $site->id)->update(['deleting_at' => now()]);

        for ($i = 0; $i < 30; $i++) {
            $this->artisan('perysee:maintenance', ['--limit' => 100])->assertSuccessful();
        }

        $this->assertSame(0, DB::table('stats_event_property_daily')->where('website_id', $site->id)->count());
        $this->assertSame(0, DB::table('event_order_ids')->where('website_id', $site->id)->count());
        $this->assertSame(1, DB::table('stats_event_property_daily')->where('website_id', $other->id)->count());
        $this->assertSame(1, DB::table('event_order_ids')->where('website_id', $other->id)->count());
    }
}
