<?php

namespace Tests\Feature;

use App\Analytics\Aggregation\Aggregator;
use App\Analytics\Ingestion\Device;
use App\Analytics\Ingestion\Sources;
use App\Installation\InstallationState;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AttributionTest extends TestCase
{
    use RefreshDatabase;

    public function test_sources_are_attributed_and_grouped_consistently_with_filters_and_csv(): void
    {
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
        $site = Website::factory()->create(['allowed_domains' => ['example.com', 'shop.example.com'], 'domain' => 'example.com', 'timezone' => 'UTC']);
        $user = User::factory()->create();
        $site->members()->attach($user, ['role' => 'owner']);
        $base = ['v' => 1, 'site' => $site->public_id, 'type' => 'pageview', 'mode' => 'pageviews', 'host' => 'example.com', 'path' => '/', 'consent' => true];
        foreach ([['ref' => null, 'utm_source' => 'ChatGPT'], ['ref' => 'https://chat.openai.com/private'], ['ref' => 'https://shop.example.com/'], ['ref' => 'https://chatgpt.com.evil.test'], ['ref' => 'not-a-url']] as $extra) {
            $this->withHeaders(['Origin' => 'https://example.com', 'User-Agent' => 'Mozilla/5.0 Chrome/130.0'])->postJson('/api/collect', $base + $extra)->assertNoContent();
        }
        app(Aggregator::class)->run();
        $this->actingAs($user);
        $url = '/api/sites/'.$site->id.'/dimensions?period=today&dimension=source&source_group=ai';
        $this->getJson($url)->assertOk()->assertJsonPath('total', 2)->assertJsonCount(1, 'data')->assertJsonPath('data.0.label', 'ChatGPT')->assertJsonPath('data.0.count', 2);
        $this->get('/sites/'.$site->id.'/sources?period=today&source_group=ai')->assertOk()->assertSee('ChatGPT')->assertDontSee('evil.test');
        $csv = $this->get('/sites/'.$site->id.'/export?period=today&dimension=source&source_group=ai')->streamedContent();
        $this->assertStringContainsString('chatgpt.com,2', $csv);
        $this->assertDatabaseHas('stats_dimension_daily', ['dimension_type' => 'source', 'dimension_value' => 'Direct', 'metric_count' => 1]);
        $this->assertDatabaseHas('stats_dimension_daily', ['dimension_type' => 'source', 'dimension_value' => 'Unknown', 'metric_count' => 1]);
    }

    public function test_provider_matching_has_explicit_boundaries_and_utm_precedence(): void
    {
        $sources = app(Sources::class);
        $this->assertSame('chatgpt', $sources->describe('chat.openai.com')['provider']);
        $this->assertNull($sources->describe('openai.com')['provider']);
        $this->assertNull($sources->describe('chatgpt.com.evil.test')['provider']);
        $this->assertSame('newsletter', $sources->resolve('newsletter', 'chatgpt.com', 'valid'));
    }

    public function test_devices_use_evidence_and_allow_a_bounded_ipad_hint(): void
    {
        $device = app(Device::class);
        foreach ([['', null, 'unknown'], ['unrecognized client', null, 'unknown'], ['Mozilla/5.0 (iPhone) Mobile Safari', null, 'mobile'], ['Mozilla/5.0 (Linux; Android 14; Tablet) Chrome/130', null, 'tablet'], ['Mozilla/5.0 (Macintosh; Intel Mac OS X) Safari/605', 'tablet', 'tablet'], ['Mozilla/5.0 (Windows NT 10.0) Chrome/130', null, 'desktop'], ['GPTBot', null, 'unknown']] as [$agent, $hint, $expected]) {
            $this->assertSame($expected, $device->classify($agent, $hint));
        }
    }

    public function test_dimension_totals_cover_more_than_the_top_hundred_rows_and_legacy_aliases_merge(): void
    {
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
        $site = Website::factory()->create(['timezone' => 'UTC']);
        $user = User::factory()->create();
        $site->members()->attach($user, ['role' => 'viewer']);
        foreach (range(1, 125) as $index) {
            $value = 'ref-'.$index.'.example';
            DB::table('stats_dimension_daily')->insert(['website_id' => $site->id, 'stat_date' => now('UTC')->toDateString(), 'dimension_type' => 'source', 'dimension_value' => $value, 'dimension_hash' => hash('sha256', $value), 'metric_count' => 1]);
        }
        foreach (['chat.openai.com', 'chatgpt.com'] as $value) {
            DB::table('stats_dimension_daily')->insert(['website_id' => $site->id, 'stat_date' => now('UTC')->toDateString(), 'dimension_type' => 'source', 'dimension_value' => $value, 'dimension_hash' => hash('sha256', $value), 'metric_count' => 2]);
        }
        $this->actingAs($user)->getJson('/api/sites/'.$site->id.'/dimensions?period=today&dimension=source')->assertOk()->assertJsonPath('total', 129)->assertJsonPath('distinct_values', 126)->assertJsonCount(100, 'data');
        $this->getJson('/api/sites/'.$site->id.'/dimensions?period=today&dimension=source&provider=chatgpt')->assertJsonPath('total', 4)->assertJsonCount(1, 'data');
    }
}
