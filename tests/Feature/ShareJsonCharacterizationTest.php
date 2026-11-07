<?php

namespace Tests\Feature;

use App\Installation\InstallationState;
use App\Models\ShareLink;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Pins the exact JSON of the public share endpoints, so that moving the serialisation into a shared presenter
 * (used by the read API too) cannot change what a share link returns.
 */
class ShareJsonCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
    }

    /** @return array{0: Website, 1: string} */
    private function seeded(array $sections): array
    {
        $site = Website::factory()->create(['name' => 'Public blog']);
        [, $token] = ShareLink::issue($site, null, 'Test', $sections, null, null);
        $day = '2026-11-03';
        DB::table('stats_daily')->insert(['website_id' => $site->id, 'stat_date' => $day, 'pageviews' => 40, 'custom_events' => 3, 'sessions' => 12, 'session_pageviews' => 30, 'dimension_pageviews' => 40, 'country_pageviews' => 38]);
        DB::table('stats_dimension_daily')->insert([
            ['website_id' => $site->id, 'stat_date' => $day, 'dimension_type' => 'path', 'dimension_value' => '/a', 'dimension_hash' => sha1('/a'), 'metric_count' => 30],
            ['website_id' => $site->id, 'stat_date' => $day, 'dimension_type' => 'path', 'dimension_value' => '/b', 'dimension_hash' => sha1('/b'), 'metric_count' => 10],
            ['website_id' => $site->id, 'stat_date' => $day, 'dimension_type' => 'country', 'dimension_value' => 'FR', 'dimension_hash' => sha1('FR'), 'metric_count' => 38],
            ['website_id' => $site->id, 'stat_date' => $day, 'dimension_type' => 'source', 'dimension_value' => 'Direct', 'dimension_hash' => sha1('Direct'), 'metric_count' => 7],
        ]);

        return [$site, $token];
    }

    public function test_overview_json_is_pinned(): void
    {
        [, $token] = $this->seeded(['overview']);
        $response = $this->getJson('/s/'.$token.'/api/overview?from=2026-11-01&to=2026-11-05')->assertOk();

        $this->assertSame([
            'period' => ['from' => '2026-11-01', 'to' => '2026-11-05', 'timezone' => 'UTC'],
            'totals' => ['pageviews' => 40, 'custom_events' => 3, 'sessions' => 12],
            'coverage' => ['measured_pageviews' => 30, 'pageviews' => 40, 'country_pageviews' => 38],
            'availability' => ['sessions' => 'partial', 'dimensions' => 'available', 'countries' => 'partial'],
            'freshness' => ['aggregated_at' => null],
        ], $response->json());
        $this->assertSame(['period', 'totals', 'coverage', 'availability', 'freshness'], array_keys($response->json()));
    }

    public function test_timeseries_json_is_pinned(): void
    {
        [, $token] = $this->seeded(['overview']);
        $json = $this->getJson('/s/'.$token.'/api/timeseries?from=2026-11-01&to=2026-11-05')->assertOk()->json();

        $this->assertSame(['data'], array_keys($json));
        $this->assertCount(5, $json['data']);
        $this->assertSame(['date', 'label', 'pageviews', 'custom_events', 'sessions', 'session_availability'], array_keys($json['data'][0]));
        $this->assertSame(['2026-11-03', 40, 3, 12], [$json['data'][2]['date'], $json['data'][2]['pageviews'], $json['data'][2]['custom_events'], $json['data'][2]['sessions']]);
    }

    public function test_dimension_json_is_pinned(): void
    {
        [, $token] = $this->seeded(['pages', 'countries']);
        $json = $this->getJson('/s/'.$token.'/api/dimensions?dimension=path&from=2026-11-01&to=2026-11-03')->assertOk()->json();

        $this->assertSame(['total', 'distinct_values', 'series', 'data', 'map'], array_keys($json));
        $this->assertSame(40, $json['total']);
        $this->assertSame(2, $json['distinct_values']);
        $this->assertSame([['value' => '/a', 'count' => 30], ['value' => '/b', 'count' => 10]], $json['data']);
        $this->assertSame([], $json['map']);
        $this->assertSame(['date', 'label', 'pageviews', 'custom_events', 'sessions'], array_keys($json['series'][0]));

        $country = $this->getJson('/s/'.$token.'/api/dimensions?dimension=country&from=2026-11-01&to=2026-11-03')->assertOk()->json();
        $this->assertSame([['value' => 'FR', 'count' => 38]], $country['map']);
        $this->assertSame([['value' => 'FR', 'count' => 38]], $country['data']);
    }

    public function test_page_props_are_pinned(): void
    {
        [, $token] = $this->seeded(['overview', 'pages']);
        $this->get('/s/'.$token.'?from=2026-11-01&to=2026-11-05&dimension=path')->assertOk()->assertInertia(function (Assert $page): void {
            $props = $page->toArray()['props'];
            $this->assertSame(['period', 'totals', 'coverage', 'availability', 'freshness'], array_keys($props['report']));
            $this->assertSame(['aggregated_at' => null], $props['report']['freshness']);
            $this->assertSame(['total', 'distinct_values', 'series', 'data', 'map'], array_keys($props['dimensionReport']));
            $this->assertSame([['value' => '/a', 'count' => 30], ['value' => '/b', 'count' => 10]], $props['dimensionReport']['data']);
            $this->assertCount(5, $props['series']);
        });
    }
}
