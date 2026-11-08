<?php

namespace Tests\Feature;

use App\Analytics\InstanceHealth;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StatsRetentionDefaultTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_default_is_unchanged_and_lives_in_the_configuration(): void
    {
        $this->assertSame(1095, config('perysee.stats_retention_days'));
    }

    public function test_an_instance_without_a_value_keeps_1095_days_everywhere(): void
    {
        $health = app(InstanceHealth::class);

        $this->assertSame(1095, $health->retentionDays('stats_retention_days'));
        $this->assertSame(1095, $health->effectiveRetention()['stats']);
        $this->assertSame(1095, $health->effectiveRetentionDays('stats_retention_days'));
        $this->assertSame(1095, $health->snapshot()['stats_retention_days']);
    }

    public function test_an_instance_that_saved_a_value_keeps_it_whatever_the_default(): void
    {
        $health = app(InstanceHealth::class);
        $health->put('stats_retention_days', 400);
        config(['perysee.stats_retention_days' => 200]);

        $this->assertSame(400, $health->retentionDays('stats_retention_days'));
        $this->assertSame(400, $health->effectiveRetention()['stats']);
    }

    public function test_the_default_follows_the_configuration_only_when_nothing_was_saved(): void
    {
        config(['perysee.stats_retention_days' => 200]);

        $this->assertSame(200, app(InstanceHealth::class)->retentionDays('stats_retention_days'));
        $this->assertSame(200, app(InstanceHealth::class)->effectiveRetention()['stats']);
    }

    public function test_the_event_property_purge_uses_the_same_default_and_the_saved_value(): void
    {
        $site = Website::factory()->create();
        $old = now('UTC')->subDays(1100)->toDateString();
        $middle = now('UTC')->subDays(500)->toDateString();
        foreach ([$old, $middle] as $day) {
            DB::table('stats_event_property_daily')->insert(['website_id' => $site->id, 'stat_date' => $day, 'event_name' => 'a', 'prop_key' => 'k', 'value_hash' => str_repeat('a', 64), 'prop_value' => 'v', 'metric_count' => 1]);
        }

        $this->artisan('perysee:prune-event-values')->assertSuccessful();
        $this->assertSame([$middle], DB::table('stats_event_property_daily')->pluck('stat_date')->all());

        app(InstanceHealth::class)->put('stats_retention_days', 300);
        $this->artisan('perysee:prune-event-values')->assertSuccessful();
        $this->assertSame(0, DB::table('stats_event_property_daily')->count());
    }

    public function test_the_literal_is_gone_from_the_places_that_used_to_repeat_it(): void
    {
        foreach (['app/Analytics/InstanceHealth.php', 'app/Console/Commands/PruneEventValues.php'] as $file) {
            $this->assertStringNotContainsString('1095', (string) file_get_contents(base_path($file)), $file);
        }
    }
}
