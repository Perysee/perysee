<?php

namespace Tests\Feature;

use App\Analytics\Aggregation\Aggregator;
use App\Analytics\Continuity\ContinuityLog;
use App\Analytics\InstanceHealth;
use App\Installation\InstallationState;
use App\Models\User;
use App\Models\Website;
use Database\Seeders\LocalDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
        $this->artisan('perysee:demo-reset', ['--seed' => 73421])->assertSuccessful();
        $sites = Website::where('is_demo', true)->orderBy('id')->get();
        $manifest = app(InstanceHealth::class)->get('demo_manifest:'.$sites[0]->id);
        $this->assertGreaterThan(1000, $manifest['pageviews']);
        $this->assertSame(5, $manifest['version']);
        $this->assertSame(now('Europe/Paris')->subDays(89)->toDateString(), $manifest['from']);
        $sessionSite = $sites->firstWhere('tracking_mode', 'sessions');
        $this->assertSame(3, DB::table('funnels')->where('website_id', $sessionSite->id)->count());
        $this->assertSame(12, DB::table('journey_reports')->where('website_id', $sessionSite->id)->where('status', 'ready')->count());
        $this->assertGreaterThan(0, DB::table('events')->where('website_id', $sessionSite->id)->where('received_at', '>=', now('UTC')->subMinutes(15))->count());
        $daily = DB::table('stats_daily')->where('website_id', $sites[0]->id)->pluck('pageviews')->all();
        $this->assertGreaterThan(20, count(array_unique($daily)));
        $this->assertSame($manifest['pageviews'], (int) DB::table('stats_segment_daily')->where('website_id', $sites[0]->id)->sum('pageviews'));
        foreach (['browser' => 'browsers', 'device_brand' => 'brands'] as $dimension => $key) {
            $this->assertSame($manifest['pageviews'], array_sum($manifest[$key]));
            $this->assertSame($manifest['pageviews'], (int) DB::table('stats_dimension_daily')->where('website_id', $sites[0]->id)->where('dimension_type', $dimension)->sum('metric_count'));
        }
        $this->assertSame($manifest['pageviews'], (int) DB::table('stats_daily')->where('website_id', $sites[0]->id)->sum('pageviews'));
        $this->assertSame($manifest['custom_events'], (int) DB::table('stats_daily')->where('website_id', $sites[0]->id)->sum('custom_events'));
        $this->assertNull(DB::table('events')->where('website_id', $real->id)->value('processed_at'));
        $this->artisan('perysee:demo-reset', ['--seed' => 73421])->assertSuccessful();
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

    public function test_demo_reset_empties_the_continuity_log_of_the_demo_sites_only(): void
    {
        $this->app->instance('env', 'local');
        User::factory()->create(['is_admin' => true]);
        $this->seed(LocalDemoSeeder::class);
        $demo = Website::where('is_demo', true)->first();
        $real = Website::factory()->create();
        $log = app(ContinuityLog::class);
        $log->record('consent', $demo->id, 'breaks', 'all', [], '2026-10-01');
        $log->record('consent', $real->id, 'breaks', 'all', [], '2026-10-01');
        $log->record('retention_change', null, 'note', 'all', []);

        $this->artisan('perysee:demo-reset', ['--seed' => 73421])->assertSuccessful();

        $this->assertSame(0, DB::table('continuity_log')->where('website_id', $demo->id)->count());
        $this->assertSame(1, DB::table('continuity_log')->where('website_id', $real->id)->count());
        $this->assertSame(1, DB::table('continuity_log')->whereNull('website_id')->count(), 'an instance entry is not the demo site\'s');
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

    public function test_perysee_demo_site_has_its_own_traffic_funnels_and_embedded_icon(): void
    {
        $this->freezeTime();
        $this->app->instance('env', 'local');
        $admin = User::factory()->create(['is_admin' => true]);
        $this->seed(LocalDemoSeeder::class);
        $site = Website::where('domain', 'perysee.com')->firstOrFail();
        $this->assertTrue($site->is_demo);
        $this->assertSame('sessions', $site->tracking_mode);
        $this->assertSame(3, Website::where('is_demo', true)->count());
        $this->artisan('perysee:demo-reset', ['--seed' => 4242, '--only' => 'perysee.com'])->assertSuccessful();
        $manifest = app(InstanceHealth::class)->get('demo_manifest:'.$site->id);
        $this->assertSame(6, $manifest['version']);
        $this->assertGreaterThan(2000, $manifest['pageviews']);
        $this->assertGreaterThan(0, $manifest['events']['download_core']);
        $this->assertSame(0, DB::table('events')->where('website_id', '!=', $site->id)->count());
        $paths = DB::table('events')->where('website_id', $site->id)->where('event_type', 1)->distinct()->pluck('path')->all();
        $this->assertContains('/product', $paths);
        $this->assertContains('/compare/matomo', $paths);
        $this->assertNotContains('/signup', $paths);
        $this->assertSame(3, DB::table('funnels')->where('website_id', $site->id)->count());
        $this->assertSame(12, DB::table('journey_reports')->where('website_id', $site->id)->where('status', 'ready')->count());
        $this->assertGreaterThan(0, DB::table('stats_dimension_daily')->where('website_id', $site->id)->where('dimension_value', 'chatgpt')->count() + DB::table('stats_dimension_daily')->where('website_id', $site->id)->where('dimension_value', 'like', '%chatgpt%')->count());
        Http::preventStrayRequests();
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
        $response = $this->actingAs($admin)->get(route('sites.favicon', $site));
        $response->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
        $this->assertStringContainsString('#5B21FF', $response->getContent());
    }
}
