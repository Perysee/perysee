<?php

namespace Tests\Feature;

use App\Analytics\Aggregation\Aggregator;
use App\Analytics\InstanceHealth;
use App\Installation\InstallationState;
use App\Models\User;
use App\Models\Website;
use Database\Seeders\LocalDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DemoAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_reset_is_deterministic_and_does_not_process_real_sites(): void
    {
        $this->freezeTime();
        $this->app->instance('env', 'local');
        $admin = User::factory()->create(['is_admin' => true]);
        $real = Website::factory()->create();
        DB::table('events')->insert(['website_id' => $real->id, 'received_at' => now('UTC'), 'path' => '/real', 'consent_given' => true]);
        $this->seed(LocalDemoSeeder::class);
        $this->artisan('perysee:demo-reset')->assertSuccessful();
        $sites = Website::where('is_demo', true)->orderBy('id')->get();
        $manifest = app(InstanceHealth::class)->get('demo_manifest:'.$sites[0]->id);
        $this->assertGreaterThan(1000, $manifest['pageviews']);
        foreach (['browser' => 'browsers', 'device_brand' => 'brands'] as $dimension => $key) {
            $this->assertSame($manifest['pageviews'], array_sum($manifest[$key]));
            $this->assertSame($manifest['pageviews'], (int) DB::table('stats_dimension_daily')->where('website_id', $sites[0]->id)->where('dimension_type', $dimension)->sum('metric_count'));
        }
        $this->assertSame($manifest['pageviews'], (int) DB::table('stats_daily')->where('website_id', $sites[0]->id)->sum('pageviews'));
        $this->assertSame($manifest['custom_events'], (int) DB::table('stats_daily')->where('website_id', $sites[0]->id)->sum('custom_events'));
        $this->assertNull(DB::table('events')->where('website_id', $real->id)->value('processed_at'));
        $this->artisan('perysee:demo-reset')->assertSuccessful();
        $this->assertSame($manifest, app(InstanceHealth::class)->get('demo_manifest:'.$sites[0]->id));
        $this->assertSame($manifest['pageviews'], (int) DB::table('stats_daily')->where('website_id', $sites[0]->id)->sum('pageviews'));
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
        $this->actingAs($admin)->get('/sites/'.$sites[0]->id)->assertOk()->assertInertia(fn (Assert $page) => $page->where('website.is_demo', true));
        $csv = $this->get('/sites/'.$sites[0]->id.'/export?period=30d')->streamedContent();
        $this->assertStringContainsString('demonstration', $csv);
    }

    public function test_demo_command_refuses_production_and_a_forged_demo_identity(): void
    {
        $this->app->instance('env', 'production');
        $this->artisan('perysee:demo-reset')->assertFailed();
        $this->app->instance('env', 'local');
        User::factory()->create(['is_admin' => true]);
        $this->seed(LocalDemoSeeder::class);
        Website::where('is_demo', true)->first()->forceFill(['is_demo' => false])->save();
        $this->artisan('perysee:demo-reset')->assertFailed();
    }

    public function test_failed_demo_aggregation_rolls_back_the_reset(): void
    {
        $this->app->instance('env', 'local');
        User::factory()->create(['is_admin' => true]);
        $this->seed(LocalDemoSeeder::class);
        $site = Website::where('is_demo', true)->first();
        DB::table('events')->insert(['website_id' => $site->id, 'path' => '/keep-on-failure', 'consent_given' => true, 'received_at' => now('UTC')]);
        $this->mock(Aggregator::class)->shouldReceive('run')->andThrow(new \RuntimeException('Simulated interruption'));
        $this->artisan('perysee:demo-reset')->assertFailed();
        $this->assertDatabaseHas('events', ['website_id' => $site->id, 'path' => '/keep-on-failure']);
        $this->assertDatabaseMissing('stats_daily', ['website_id' => $site->id]);
    }
}
