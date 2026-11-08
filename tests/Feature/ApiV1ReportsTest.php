<?php

namespace Tests\Feature;

use App\Analytics\Aggregation\Aggregator;
use App\Analytics\Continuity\ContinuityLog;
use App\Installation\InstallationState;
use App\Models\ApiToken;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ApiV1ReportsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
    }

    private function token(array $scopes, ?Website $site = null): array
    {
        $site ??= Website::factory()->create(['tracking_mode' => 'sessions']);
        [$token, $secret] = ApiToken::issue($site, null, 'Test', $scopes, 300, null);

        return [$site, $token, ['Authorization' => 'Bearer '.$secret]];
    }

    private function event(Website $site, string $time, string $path = '/pricing', string $country = 'FR', string $device = 'mobile', string $source = 'chatgpt', ?string $session = null, int $type = 1, ?string $name = null): void
    {
        DB::table('events')->insert(['website_id' => $site->id, 'received_at' => $time, 'path' => $path, 'event_type' => $type, 'event_name' => $name, 'country_code' => $country, 'geo_available' => true, 'device_type' => $device, 'source_value' => $source, 'dimensions_available' => true, 'tracking_mode' => 'sessions', 'session_key' => $session, 'consent_given' => true]);
    }

    private function seedTraffic(Website $site): void
    {
        $this->event($site, '2026-10-24 10:00:00', '/pricing', 'FR', 'mobile', 'chatgpt', 'a');
        $this->event($site, '2026-10-24 11:00:00', '/docs', 'FR', 'desktop', 'chatgpt', 'b');
        $this->event($site, '2026-10-24 12:00:00', '/pricing', 'US', 'mobile', 'newsletter', 'c');
        $this->event($site, '2026-10-24 12:30:00', '/pricing', 'US', 'mobile', 'newsletter', 'c', 2, 'signup');
        app(Aggregator::class)->run();
    }

    public function test_overview_matches_the_interface_and_has_an_exact_set_of_keys(): void
    {
        [$site, , $auth] = $this->token(['overview']);
        $this->seedTraffic($site);
        $range = '?from=2026-10-20&to=2026-10-26';
        $response = $this->getJson('/api/v1/overview'.$range, $auth)->assertOk();
        $json = $response->json();

        $this->assertSame(['data', 'meta'], array_keys($json));
        $this->assertSame(['period', 'totals', 'coverage', 'availability', 'freshness', 'measurement_periods', 'continuity'], array_keys($json['data']));
        $this->assertSame(['aggregated_at', 'pending'], array_keys($json['data']['freshness']));
        $this->assertSame(['api_version' => 1], $json['meta']);

        $owner = User::factory()->create();
        $site->members()->attach($owner, ['role' => 'owner']);
        $internal = $this->actingAs($owner)->getJson('/api/sites/'.$site->id.'/overview'.$range)->json();
        foreach (['period', 'totals', 'coverage', 'availability'] as $key) {
            $this->assertSame($internal[$key], $json['data'][$key], $key);
        }
        $this->assertSame($internal['freshness']['aggregated_at'], $json['data']['freshness']['aggregated_at']);
        $this->assertSame(3, $json['data']['totals']['pageviews']);
        $this->assertSame(1, $json['data']['totals']['custom_events']);
        // Nothing instance-level or private leaks.
        $content = $response->getContent();
        foreach (['attribution_v2_since', 'public_id', $site->public_id] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $content);
        }
    }

    public function test_continuity_lists_the_period_entries_without_any_payload(): void
    {
        [$site, , $auth] = $this->token(['overview']);
        [$other] = $this->token(['overview']);
        $log = app(ContinuityLog::class);
        $log->record('consent', $site->id, 'breaks', 'all', ['from' => true, 'to' => false, 'secret_marker' => 'x'], '2026-10-22');
        $log->record('timezone', $site->id, 'note', 'all', [], '2026-10-01');
        $log->record('currency', $site->id, 'breaks', 'events', [], '2026-10-23');
        $log->record('consent', $other->id, 'breaks', 'all', [], '2026-10-22');
        $expected = [
            ['at' => '2026-10-22', 'kind' => 'consent', 'scope' => 'all', 'severity' => 'breaks'],
            ['at' => '2026-10-23', 'kind' => 'currency', 'scope' => 'events', 'severity' => 'breaks'],
        ];

        $overview = $this->getJson('/api/v1/overview?from=2026-10-20&to=2026-10-26', $auth)->assertOk();
        $this->assertSame($expected, $overview->json('data.continuity'));
        $series = $this->getJson('/api/v1/timeseries?from=2026-10-20&to=2026-10-26', $auth)->assertOk();
        $this->assertSame($expected, $series->json('data.continuity'));
        $this->assertSame([['at' => '2026-10-01', 'kind' => 'timezone', 'scope' => 'all', 'severity' => 'note']], $this->getJson('/api/v1/overview?from=2026-10-01&to=2026-10-02', $auth)->json('data.continuity'));
        $this->assertSame([], $this->getJson('/api/v1/overview?from=2026-09-01&to=2026-09-02', $auth)->json('data.continuity'));
        foreach ([$overview, $series] as $response) {
            $this->assertStringNotContainsString('payload', $response->getContent());
            $this->assertStringNotContainsString('secret_marker', $response->getContent());
        }
    }

    public function test_continuity_costs_one_select_per_request(): void
    {
        [$site, , $auth] = $this->token(['overview']);
        app(ContinuityLog::class)->record('consent', $site->id, 'breaks', 'all', [], '2026-10-22');
        $reads = 0;
        DB::listen(function ($query) use (&$reads): void {
            $reads += str_contains($query->sql, 'continuity_log') ? 1 : 0;
        });

        $this->getJson('/api/v1/overview?from=2026-10-20&to=2026-10-26&compare=previous', $auth)->assertOk();
        $this->assertSame(2, $reads, 'one read for the entries of the response, one for the comparison');
        $reads = 0;
        $this->getJson('/api/v1/timeseries?from=2026-10-20&to=2026-10-26', $auth)->assertOk();
        $this->assertSame(1, $reads);
    }

    public function test_unavailable_sessions_are_null_never_zero(): void
    {
        [$site, , $auth] = $this->token(['overview']);
        DB::table('stats_daily')->insert(['website_id' => $site->id, 'stat_date' => '2026-10-24', 'pageviews' => 10, 'sessions' => 0, 'session_pageviews' => 0]);
        DB::table('site_measurement_periods')->insert(['website_id' => $site->id, 'mode' => 'pageviews', 'started_at' => '2026-10-01 00:00:00']);
        $json = $this->getJson('/api/v1/overview?from=2026-10-24&to=2026-10-24', $auth)->assertOk()->json('data');
        $this->assertNull($json['totals']['sessions']);
        $this->assertSame('unavailable', $json['availability']['sessions']);
    }

    public function test_comparison_reuses_the_server_verdict(): void
    {
        [$site, , $auth] = $this->token(['overview', 'devices']);
        $this->seedTraffic($site);
        $owner = User::factory()->create();
        $site->members()->attach($owner, ['role' => 'owner']);
        $today = now('UTC')->toDateString();
        foreach (['?from=2026-10-24&to=2026-10-24', '?from=2026-10-20&to=2026-10-26', '?from=2026-10-20&to='.$today, '?from=2026-10-20&to=2026-10-26&filters[device][]=mobile'] as $range) {
            $json = $this->getJson('/api/v1/overview'.$range.'&compare=previous', $auth)->assertOk()->json('data');
            $internal = $this->actingAs($owner)->getJson('/api/sites/'.$site->id.'/overview'.$range.'&compare=previous')->json('comparison');
            $this->assertSame(['period', 'totals', 'coverage', 'availability', 'reliability'], array_slice(array_keys($json['comparison']), 0, 5), $range);
            $this->assertSame($internal['reliability'], $json['comparison']['reliability'], $range);
            $this->assertSame($internal['totals'], $json['comparison']['totals'], $range);
            $this->assertSame($internal['period'], $json['comparison']['period'], $range);
        }
        $json = $this->getJson('/api/v1/overview?from=2026-10-20&to='.$today.'&compare=previous', $auth)->json('data');
        $this->assertSame(['percent_allowed' => false, 'reason' => 'current_period'], $json['comparison']['reliability']['pageviews']);
        $json = $this->getJson('/api/v1/overview?from=2026-10-24&to=2026-10-26&compare=previous', $auth)->json('data');
        $this->assertSame(['percent_allowed' => false, 'reason' => 'previous_zero'], $json['comparison']['reliability']['pageviews']);
        $this->getJson('/api/v1/overview?from=2026-10-20&to=2026-10-26', $auth)->assertJsonMissingPath('data.comparison');
        $this->getJson('/api/v1/overview?from=2026-10-20&to=2026-10-26&compare=year', $auth)->assertStatus(422);
    }

    public function test_period_validation_gives_422_with_the_envelope(): void
    {
        [, , $auth] = $this->token(['overview']);
        $tomorrow = now('UTC')->addDay()->toDateString();
        $this->getJson('/api/v1/overview?from=2026-10-26&to=2026-10-20', $auth)->assertStatus(422)->assertJsonPath('error.code', 'validation_failed')->assertJsonStructure(['error' => ['code', 'message', 'details' => ['errors']]]);
        $this->getJson('/api/v1/overview?from=2020-01-01&to=2026-10-20', $auth)->assertStatus(422)->assertJsonPath('error.details.errors.from.0', fn ($message) => is_string($message));
        $this->getJson('/api/v1/overview?from=2026-10-20&to='.$tomorrow, $auth)->assertStatus(422);
        $this->getJson('/api/v1/overview?period=yearly', $auth)->assertStatus(422);
        $this->getJson('/api/v1/overview?from=2026-10-20&to=2026-10-22&granularity=hour', $auth)->assertStatus(422);
        $this->getJson('/api/v1/overview?from=nonsense&to=2026-10-22', $auth)->assertStatus(422);
        $this->getJson('/api/v1/overview?filters[bogus][]=x', $auth)->assertStatus(422);
        $this->getJson('/api/v1/overview?period=30d', $auth)->assertOk();
    }

    public function test_timeseries(): void
    {
        [$site, , $auth] = $this->token(['overview']);
        $this->seedTraffic($site);
        $json = $this->getJson('/api/v1/timeseries?from=2026-10-23&to=2026-10-25', $auth)->assertOk()->json();
        $this->assertSame(['data', 'meta'], array_keys($json));
        $this->assertSame(['period', 'granularity', 'points', 'continuity'], array_keys($json['data']));
        $this->assertSame('day', $json['data']['granularity']);
        $this->assertCount(3, $json['data']['points']);
        $this->assertSame(['date', 'label', 'pageviews', 'custom_events', 'sessions', 'session_availability'], array_keys($json['data']['points'][1]));
        $this->assertSame(['2026-10-24', 3, 1], [$json['data']['points'][1]['date'], $json['data']['points'][1]['pageviews'], $json['data']['points'][1]['custom_events']]);
        $hourly = $this->getJson('/api/v1/timeseries?from=2026-10-24&to=2026-10-24', $auth)->assertOk()->json('data');
        $this->assertSame('hour', $hourly['granularity']);
        $this->assertCount(24, $hourly['points']);
        $this->getJson('/api/v1/timeseries?from=2026-10-23&to=2026-10-25&granularity=hour', $auth)->assertStatus(422);
    }

    public function test_dimensions_match_the_csv_value_by_value(): void
    {
        [$site, , $auth] = $this->token(['pages', 'sources', 'campaigns', 'devices', 'countries', 'events']);
        $this->seedTraffic($site);
        $owner = User::factory()->create();
        $site->members()->attach($owner, ['role' => 'owner']);
        $range = 'from=2026-10-20&to=2026-10-26';
        foreach (['path', 'source', 'device', 'country', 'event', 'browser', 'device_brand', 'campaign'] as $dimension) {
            $json = $this->getJson('/api/v1/dimensions/'.$dimension.'?'.$range, $auth)->assertOk()->json();
            $csv = $this->actingAs($owner)->get('/sites/'.$site->id.'/export?'.$range.'&dimension='.$dimension)->assertOk()->streamedContent();
            $lines = array_values(array_filter(explode("\n", $csv)));
            array_shift($lines);
            $expected = array_map(fn (string $line): array => ['value' => explode(',', $line)[0], 'count' => (int) explode(',', $line)[1]], $lines);
            $actual = array_map(fn (array $row): array => ['value' => $row['value'], 'count' => $row['count']], $json['data']['rows']);
            $this->assertSame($expected, $actual, $dimension);
            $this->assertSame($dimension, $json['data']['dimension']);
        }
        $json = $this->getJson('/api/v1/dimensions/path?'.$range, $auth)->json();
        $this->assertSame(['dimension', 'period', 'total', 'distinct_values', 'series', 'rows'], array_keys($json['data']));
        $this->assertSame(['api_version', 'limit', 'truncated'], array_keys($json['meta']));
        $this->assertSame(['date', 'pageviews', 'custom_events'], array_keys($json['data']['series'][0]));
        $this->assertSame([['value' => '/pricing', 'count' => 2], ['value' => '/docs', 'count' => 1]], $json['data']['rows']);
        $this->assertFalse($json['meta']['truncated']);
        $this->assertSame(100, $json['meta']['limit']);
        // Source rows carry the description the interface shows.
        $source = $this->getJson('/api/v1/dimensions/source?'.$range, $auth)->json('data.rows.0');
        $this->assertArrayHasKey('value', $source);
        $this->assertArrayHasKey('count', $source);
    }

    public function test_more_than_a_hundred_values_are_truncated_with_the_true_distinct_count(): void
    {
        [$site, , $auth] = $this->token(['pages']);
        $rows = [];
        for ($i = 0; $i < 130; $i++) {
            $path = '/p'.str_pad((string) $i, 3, '0', STR_PAD_LEFT);
            $rows[] = ['website_id' => $site->id, 'stat_date' => '2026-10-24', 'dimension_type' => 'path', 'dimension_value' => $path, 'dimension_hash' => sha1($path), 'metric_count' => 200 - $i];
        }
        DB::table('stats_dimension_daily')->insert($rows);
        $json = $this->getJson('/api/v1/dimensions/path?from=2026-10-24&to=2026-10-24', $auth)->assertOk()->json();
        $this->assertCount(100, $json['data']['rows']);
        $this->assertSame(130, $json['data']['distinct_values']);
        $this->assertTrue($json['meta']['truncated']);
        $this->assertSame('/p000', $json['data']['rows'][0]['value']);
        $this->assertSame(array_sum(range(71, 200)), $json['data']['total']);
    }

    public function test_filters_combine_or_inside_and_and_between_dimensions(): void
    {
        [$site, , $auth] = $this->token(['overview', 'pages', 'countries', 'devices']);
        $this->seedTraffic($site);
        $base = '/api/v1/overview?from=2026-10-24&to=2026-10-24';
        $this->assertSame(2, $this->getJson($base.'&filters[country][]=FR', $auth)->json('data.totals.pageviews'));
        $this->assertSame(3, $this->getJson($base.'&filters[country][]=FR&filters[country][]=US', $auth)->json('data.totals.pageviews'));
        $this->assertSame(1, $this->getJson($base.'&filters[country][]=FR&filters[device][]=mobile', $auth)->json('data.totals.pageviews'));
        $this->assertSame(1, $this->getJson($base.'&filters[country][]=FR&filters[country][]=US&filters[device][]=desktop', $auth)->json('data.totals.pageviews'));
        $rows = $this->getJson('/api/v1/dimensions/path?from=2026-10-24&to=2026-10-24&filters[country][]=FR', $auth)->assertOk()->json('data.rows');
        $this->assertSame([['value' => '/docs', 'count' => 1], ['value' => '/pricing', 'count' => 1]], array_map(fn ($row) => ['value' => $row['value'], 'count' => $row['count']], $rows));
        $many = '';
        for ($i = 0; $i < 21; $i++) {
            $many .= '&filters[country][]=C'.$i;
        }
        $this->getJson($base.$many, $auth)->assertStatus(422);
    }

    public function test_unknown_dimension_is_404(): void
    {
        [, , $auth] = $this->token(['overview', 'pages']);
        $this->getJson('/api/v1/dimensions/provider', $auth)->assertNotFound()->assertJsonPath('error.code', 'not_found');
        $this->getJson('/api/v1/dimensions/nonsense', $auth)->assertNotFound();
        $this->getJson('/api/v1/dimensions/path;drop', $auth)->assertNotFound();
    }

    public function test_scopes_are_enforced_on_endpoints_and_filters(): void
    {
        [$site, , $auth] = $this->token(['pages']);
        $this->seedTraffic($site);
        $denied = fn (string $url, string $required) => $this->getJson($url, $auth)->assertForbidden()->assertJsonPath('error.code', 'insufficient_scope')->assertJsonPath('error.details.required', $required);
        $denied('/api/v1/overview', 'overview');
        $denied('/api/v1/timeseries', 'overview');
        $denied('/api/v1/dimensions/country', 'countries');
        $denied('/api/v1/dimensions/source', 'sources');
        $denied('/api/v1/dimensions/campaign', 'campaigns');
        $denied('/api/v1/dimensions/device', 'devices');
        $denied('/api/v1/dimensions/browser', 'devices');
        $denied('/api/v1/dimensions/device_brand', 'devices');
        $denied('/api/v1/dimensions/event', 'events');
        $denied('/api/v1/goals', 'goals');
        $denied('/api/v1/funnels', 'funnels');
        // A filter on a dimension outside the scopes would reveal a breakdown by that dimension.
        $denied('/api/v1/dimensions/path?filters[country][]=FR', 'countries');
        $denied('/api/v1/dimensions/path?filters[provider][]=chatgpt', 'sources');
        $denied('/api/v1/dimensions/path?source_group=ai', 'sources');
        $denied('/api/v1/dimensions/path?provider=chatgpt', 'sources');
        $this->getJson('/api/v1/dimensions/path?from=2026-10-24&to=2026-10-24&filters[path][]=/docs', $auth)->assertOk();
        // The scope check does not depend on the order of the filters or the presence of a valid one.
        $this->getJson('/api/v1/dimensions/path?filters[path][]=/docs&filters[device][]=mobile', $auth)->assertForbidden();
        $this->getJson('/api/v1/site', $auth)->assertOk();
    }

    public function test_nothing_of_another_site_is_ever_returned(): void
    {
        [$site, , $auth] = $this->token(['overview', 'pages']);
        $other = Website::factory()->create();
        DB::table('stats_daily')->insert([['website_id' => $site->id, 'stat_date' => '2026-10-24', 'pageviews' => 5], ['website_id' => $other->id, 'stat_date' => '2026-10-24', 'pageviews' => 900]]);
        DB::table('stats_dimension_daily')->insert([
            ['website_id' => $site->id, 'stat_date' => '2026-10-24', 'dimension_type' => 'path', 'dimension_value' => '/mine', 'dimension_hash' => sha1('/mine'), 'metric_count' => 5],
            ['website_id' => $other->id, 'stat_date' => '2026-10-24', 'dimension_type' => 'path', 'dimension_value' => '/theirs', 'dimension_hash' => sha1('/theirs'), 'metric_count' => 900],
        ]);
        $query = 'from=2026-10-24&to=2026-10-24&website_id='.$other->id.'&site='.$other->id.'&website='.$other->id;
        $this->assertSame(5, $this->getJson('/api/v1/overview?'.$query, $auth)->json('data.totals.pageviews'));
        $content = $this->getJson('/api/v1/dimensions/path?'.$query, $auth)->assertOk()->getContent();
        $this->assertStringContainsString('/mine', $content);
        $this->assertStringNotContainsString('/theirs', $content);
    }

    public function test_every_authenticated_response_carries_rate_headers(): void
    {
        [, , $auth] = $this->token(['overview']);
        $response = $this->getJson('/api/v1/overview', $auth)->assertOk();
        $this->assertSame('300', $response->headers->get('X-RateLimit-Limit'));
        $this->assertSame('299', $response->headers->get('X-RateLimit-Remaining'));
        $this->assertGreaterThan(now()->timestamp - 1, (int) $response->headers->get('X-RateLimit-Reset'));
        $forbidden = $this->getJson('/api/v1/dimensions/country', $auth)->assertForbidden();
        $this->assertSame('298', $forbidden->headers->get('X-RateLimit-Remaining'));
    }
}
