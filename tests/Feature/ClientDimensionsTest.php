<?php

namespace Tests\Feature;

use App\Analytics\Aggregation\Aggregator;
use App\Analytics\Ingestion\Device;
use App\Installation\InstallationState;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ClientDimensionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_browser_precedence_and_brands_require_explicit_evidence(): void
    {
        $device = app(Device::class);
        foreach ([
            ['Mozilla/5.0 (Windows NT 10.0) Chrome/140.0 Safari/537.36 Edg/140.0', 'edge', 'unknown'],
            ['Mozilla/5.0 (Linux; Android 14; SM-S921B) Chrome/140.0 Mobile Safari/537.36 SamsungBrowser/28.0', 'samsung', 'samsung'],
            ['Mozilla/5.0 (iPhone; CPU iPhone OS 18) AppleWebKit/605.1 CriOS/140.0 Mobile Safari/604.1', 'chrome', 'apple'],
            ['Mozilla/5.0 (iPad) Version/18.0 Mobile Safari/605.1', 'safari', 'apple'],
            ['Mozilla/5.0 (Linux; Android 10; K) Chrome/140.0 Mobile Safari/537.36', 'chrome', 'unknown'],
            ['Mozilla/5.0 (Linux; Android 14; Pixel 9) Chrome/140.0 Mobile Safari/537.36', 'chrome', 'google'],
            ['Mozilla/5.0 (X11) Chrome/140.0 Safari/537.36 OPR/125.0', 'opera', 'unknown'],
            ['Mozilla/5.0 (Macintosh) Firefox/140.0', 'firefox', 'apple'],
            ['Googlebot Chrome/140.0', 'unknown', 'unknown'],
            ['', 'unknown', 'unknown'],
        ] as [$agent, $browser, $brand]) {
            $this->assertSame($browser, $device->details($agent)['browser_name']);
            $this->assertSame($brand, $device->details($agent)['device_brand']);
        }
    }

    public function test_new_collection_aggregates_client_dimensions_without_reinventing_history(): void
    {
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
        $site = Website::factory()->create(['timezone' => 'UTC']);
        $user = User::factory()->create();
        $site->members()->attach($user, ['role' => 'viewer']);
        DB::table('events')->insert(['website_id' => $site->id, 'received_at' => now('UTC'), 'path' => '/old', 'consent_given' => true]);
        $payload = ['v' => 1, 'site' => $site->public_id, 'type' => 'pageview', 'mode' => 'pageviews', 'host' => $site->domain, 'path' => '/new', 'consent' => true];
        $this->withHeaders(['Origin' => 'https://'.$site->domain, 'User-Agent' => 'Mozilla/5.0 (iPhone) Version/18.0 Mobile Safari/605.1'])->postJson('/api/collect', $payload)->assertNoContent();
        $this->postJson('/api/collect', $payload + ['browser_name' => 'forged'])->assertUnprocessable();
        $this->assertDatabaseHas('events', ['path' => '/new', 'device_brand' => 'apple', 'browser_name' => 'safari']);
        $this->assertSame(2, app(Aggregator::class)->run(1));
        $this->assertSame(0, app(Aggregator::class)->run(1));
        $this->assertDatabaseHas('stats_dimension_daily', ['dimension_type' => 'browser', 'dimension_value' => 'safari', 'metric_count' => 1]);
        $this->assertDatabaseHas('stats_dimension_daily', ['dimension_type' => 'device_brand', 'dimension_value' => 'apple', 'metric_count' => 1]);
        $this->assertSame(1, (int) DB::table('stats_dimension_daily')->where('dimension_type', 'browser')->sum('metric_count'));
        $this->actingAs($user)->getJson('/api/sites/'.$site->id.'/dimensions?period=today&dimension=browser')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.value', 'safari');
        $this->get('/sites/'.$site->id.'/devices?period=today&dimension=browser&value=safari')->assertInertia(fn (Assert $page) => $page->where('dimension', 'browser')->where('dimensionReport.total', 1)->where('technologyCoverage.browser', 1));
        $csv = $this->get('/sites/'.$site->id.'/export?period=today&dimension=browser&value=safari')->streamedContent();
        $this->assertStringContainsString('safari,1', $csv);
        $this->actingAs(User::factory()->create(['is_admin' => true]))->get('/sites/'.$site->id.'/devices?dimension=browser')->assertForbidden();
    }

    public function test_country_selection_keeps_the_world_context_and_filtered_totals_separate(): void
    {
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
        $site = Website::factory()->create(['timezone' => 'UTC']);
        $user = User::factory()->create();
        $site->members()->attach($user, ['role' => 'viewer']);
        foreach (['FR' => 3, 'US' => 2] as $country => $count) {
            DB::table('stats_dimension_daily')->insert(['website_id' => $site->id, 'stat_date' => now('UTC')->toDateString(), 'dimension_type' => 'country', 'dimension_hash' => hash('sha256', $country), 'dimension_value' => $country, 'metric_count' => $count]);
        }
        $this->actingAs($user)->get('/sites/'.$site->id.'/countries?period=today&value=FR')
            ->assertInertia(fn (Assert $page) => $page->where('dimensionReport.total', 3)->has('countryContext', 2));
        $this->get('/sites/'.$site->id.'/devices?dimension=source')->assertSessionHasErrors('dimension');
    }
}
