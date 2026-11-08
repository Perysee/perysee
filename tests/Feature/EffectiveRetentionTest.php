<?php

namespace Tests\Feature;

use App\Analytics\InstanceHealth;
use App\Analytics\StorageTiers;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EffectiveRetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_tier_only_ever_lowers_a_retention(): void
    {
        foreach ([1, 3, 7, 15, 30, 90, 365, 3650] as $setting) {
            for ($tier = 0; $tier <= StorageTiers::MAX; $tier++) {
                $this->assertLessThanOrEqual($setting, StorageTiers::rawDays($setting, $tier));
                $this->assertLessThanOrEqual($setting, StorageTiers::hourlyDays($setting, $tier));
                if ($tier > 0) {
                    $this->assertLessThanOrEqual(StorageTiers::rawDays($setting, $tier - 1), StorageTiers::rawDays($setting, $tier), 'A higher tier never keeps more.');
                    $this->assertLessThanOrEqual(StorageTiers::hourlyDays($setting, $tier - 1), StorageTiers::hourlyDays($setting, $tier));
                }
            }
        }
    }

    public function test_the_caps_of_each_tier(): void
    {
        $this->assertSame([90, 30, 7, 7, 7], array_map(fn (int $tier): int => StorageTiers::rawDays(90, $tier), range(0, 4)));
        $this->assertSame([1095, 1095, 1095, 90, 30], array_map(fn (int $tier): int => StorageTiers::hourlyDays(1095, $tier), range(0, 4)));
        $this->assertSame(['raw_days' => 7, 'hourly_days' => 90], StorageTiers::caps(3));
        $this->assertSame(['raw_days' => null, 'hourly_days' => null], StorageTiers::caps(0));
    }

    public function test_a_setting_below_a_cap_is_never_raised_by_it(): void
    {
        $this->assertSame(5, StorageTiers::rawDays(5, 1));
        $this->assertSame(5, StorageTiers::rawDays(5, 2));
        $this->assertSame(20, StorageTiers::hourlyDays(20, 4));
    }

    public function test_the_effective_retention_is_one_read_and_follows_the_tier(): void
    {
        $health = app(InstanceHealth::class);
        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $this->assertSame(['raw' => 90, 'stats' => 1095, 'hourly' => 1095, 'tier' => 0], $health->effectiveRetention());
        $this->assertCount(1, $statements);

        $health->put('storage_tier', 2);
        $this->assertSame(['raw' => 7, 'stats' => 1095, 'hourly' => 1095, 'tier' => 2], $health->effectiveRetention());
        $health->put('storage_tier', 4);
        $this->assertSame(['raw' => 7, 'stats' => 1095, 'hourly' => 30, 'tier' => 4], $health->effectiveRetention());
        $this->assertSame(90, $health->retentionDays('raw_retention_days'), 'The administrator setting is not rewritten.');
    }

    public function test_an_unknown_tier_in_the_state_is_ignored_safely(): void
    {
        $health = app(InstanceHealth::class);
        $health->put('storage_tier', 99);
        $this->assertSame(4, $health->effectiveRetention()['tier']);
        $health->put('storage_tier', -3);
        $this->assertSame(0, $health->effectiveRetention()['tier']);
    }

    private function event(Website $site, int $daysAgo): int
    {
        return DB::table('events')->insertGetId(['website_id' => $site->id, 'received_at' => now('UTC')->subDays($daysAgo), 'event_type' => 1, 'path' => '/', 'processed_at' => now(), 'consent_given' => true]);
    }

    public function test_the_hourly_event_purge_applies_the_effective_raw_retention(): void
    {
        $site = Website::factory()->create();
        $old = $this->event($site, 40);
        $kept = $this->event($site, 10);
        $this->artisan('perysee:prune-tracking')->assertSuccessful();
        $this->assertSame(2, DB::table('events')->count(), 'Normal tier: 90 days.');

        app(InstanceHealth::class)->put('storage_tier', 1);
        $this->artisan('perysee:prune-tracking')->assertSuccessful();
        $this->assertSame([$kept], DB::table('events')->pluck('id')->all());
        $this->assertNotContains($old, DB::table('events')->pluck('id')->all());

        app(InstanceHealth::class)->put('storage_tier', 2);
        $this->artisan('perysee:prune-tracking')->assertSuccessful();
        $this->assertSame([], DB::table('events')->pluck('id')->all(), 'Tier 2: 7 days.');
    }

    public function test_a_raw_retention_below_the_cap_is_kept_as_the_administrator_set_it(): void
    {
        $site = Website::factory()->create();
        app(InstanceHealth::class)->put('raw_retention_days', 5);
        app(InstanceHealth::class)->put('storage_tier', 1);
        $this->event($site, 6);
        $kept = $this->event($site, 4);

        $this->artisan('perysee:prune-tracking')->assertSuccessful();

        $this->assertSame([$kept], DB::table('events')->pluck('id')->all());
    }

    public function test_the_hourly_aggregates_follow_the_tier_and_the_daily_ones_do_not(): void
    {
        $site = Website::factory()->create();
        $old = now('UTC')->subDays(60);
        DB::table('stats_hourly')->insert(['website_id' => $site->id, 'bucket_start' => $old->copy()->startOfHour(), 'stat_date' => $old->toDateString(), 'pageviews' => 1]);
        DB::table('stats_daily')->insert(['website_id' => $site->id, 'stat_date' => $old->toDateString(), 'pageviews' => 1]);
        DB::table('stats_segment_hourly')->insert(['website_id' => $site->id, 'bucket_start' => $old->copy()->startOfHour(), 'segment_hash' => str_repeat('a', 64), 'stat_date' => $old->toDateString(), 'dimensions' => '{}', 'pageviews' => 1]);

        $this->artisan('perysee:maintenance')->assertSuccessful();
        $this->assertSame(1, DB::table('stats_hourly')->count(), 'Tier 0 keeps the hourly aggregates as long as the daily ones.');

        app(InstanceHealth::class)->put('storage_tier', 3);
        $this->artisan('perysee:maintenance')->assertSuccessful();
        $this->assertSame(1, DB::table('stats_hourly')->count(), '60 days is inside the 90 days of tier 3.');

        app(InstanceHealth::class)->put('storage_tier', 4);
        $this->artisan('perysee:maintenance')->assertSuccessful();
        $this->assertSame(0, DB::table('stats_hourly')->count());
        $this->assertSame(0, DB::table('stats_segment_hourly')->count());
        $this->assertSame(1, DB::table('stats_daily')->count(), 'The daily aggregates are never touched by a tier.');
    }

    public function test_the_hourly_cut_still_waits_for_an_older_unprocessed_event(): void
    {
        $site = Website::factory()->create();
        $old = now('UTC')->subDays(60);
        DB::table('stats_hourly')->insert(['website_id' => $site->id, 'bucket_start' => $old->copy()->startOfHour(), 'stat_date' => $old->toDateString(), 'pageviews' => 1]);
        DB::table('events')->insert(['website_id' => $site->id, 'received_at' => $old, 'event_type' => 1, 'path' => '/', 'processed_at' => null, 'consent_given' => true]);
        app(InstanceHealth::class)->put('storage_tier', 4);

        $this->artisan('perysee:maintenance')->assertSuccessful();

        $this->assertSame(1, DB::table('stats_hourly')->count());
    }
}
