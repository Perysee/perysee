<?php

namespace Tests\Feature;

use App\Analytics\Aggregation\Aggregator;
use App\Analytics\Reporting\JourneyReports;
use App\Installation\InstallationState;
use App\Models\ApiToken;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ApiV1GoalsFunnelsTest extends TestCase
{
    use RefreshDatabase;

    private Website $site;

    private User $owner;

    private array $auth;

    protected function setUp(): void
    {
        parent::setUp();
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
        $this->travelTo(now('UTC')->setDate(2026, 10, 26)->setTime(12, 0));
        $this->site = Website::factory()->create(['tracking_mode' => 'sessions']);
        $this->owner = User::factory()->create();
        $this->site->members()->attach($this->owner, ['role' => 'owner']);
        [, $secret] = ApiToken::issue($this->site, $this->owner, 'Goals', ['goals', 'funnels', 'countries'], 300, null);
        $this->auth = ['Authorization' => 'Bearer '.$secret];
    }

    private function goal(Website $site, string $name = 'Signup', bool $archived = false): int
    {
        $id = DB::table('goals')->insertGetId(['website_id' => $site->id, 'name' => $name, 'current_version' => 1, 'archived_at' => $archived ? now() : null, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('goal_versions')->insert(['website_id' => $site->id, 'goal_id' => $id, 'version' => 1, 'type' => 'event', 'value' => 'signup', 'threshold' => 1, 'created_at' => now('UTC')->subDays(30)]);

        return $id;
    }

    private function event(string $session, string $time, int $type = 1, ?string $name = null, string $path = '/'): void
    {
        DB::table('events')->insert(['website_id' => $this->site->id, 'received_at' => '2026-10-24 '.$time, 'event_type' => $type, 'event_name' => $name, 'path' => $path, 'tracking_mode' => 'sessions', 'session_key' => $session, 'country_code' => 'FR', 'geo_available' => true, 'device_type' => 'mobile', 'dimensions_available' => true, 'source_value' => 'chatgpt', 'consent_given' => true]);
    }

    public function test_goals_are_listed_with_their_current_definition_including_archived_ones(): void
    {
        $active = $this->goal($this->site, 'Signup');
        $archived = $this->goal($this->site, 'Old goal', true);
        $this->goal(Website::factory()->create(), 'Foreign goal');

        $json = $this->getJson('/api/v1/goals', $this->auth)->assertOk()->json();
        $this->assertSame(['data', 'meta'], array_keys($json));
        $this->assertSame(['id', 'name', 'archived', 'version', 'type', 'value', 'threshold'], array_keys($json['data'][0]));
        $byId = collect($json['data'])->keyBy('id');
        $this->assertCount(2, $json['data']);
        $this->assertFalse($byId[$active]['archived']);
        $this->assertTrue($byId[$archived]['archived']);
        $this->assertStringNotContainsString('Foreign goal', json_encode($json));
    }

    public function test_a_goal_report_matches_the_interface(): void
    {
        $goal = $this->goal($this->site);
        $this->event('s1', '10:00:00');
        $this->event('s1', '10:01:00', 2, 'signup');
        $this->event('s2', '11:00:00');
        app(Aggregator::class)->run();
        $range = '?from=2026-10-24&to=2026-10-26';

        $api = $this->getJson('/api/v1/goals/'.$goal.$range, $this->auth)->assertOk()->json();
        $internal = $this->actingAs($this->owner)->getJson('/api/sites/'.$this->site->id.'/goals/'.$goal.$range)->json();
        $this->assertSame(['goal', 'period', 'report'], array_keys($api['data']));
        $this->assertSame(['id', 'name', 'archived', 'version', 'type', 'value', 'threshold'], array_keys($api['data']['goal']));
        $this->assertSame($internal['goal'], $api['data']['goal']);
        $this->assertSame(['from' => '2026-10-24', 'to' => '2026-10-26'], $api['data']['period']);
        foreach (['mode', 'totals', 'series', 'breakdown', 'coverage'] as $key) {
            $this->assertSame($internal[$key], $api['data']['report'][$key], $key);
        }
        $this->assertSame(['mode', 'totals', 'series', 'breakdown', 'coverage'], array_keys($api['data']['report']));
        $this->assertSame(1, $api['data']['report']['totals']['conversions']);
        // A version that does not exist, a filter outside the scopes and an invalid period.
        $this->getJson('/api/v1/goals/'.$goal.$range.'&version=9', $this->auth)->assertNotFound();
        $this->getJson('/api/v1/goals/'.$goal.$range.'&version=0', $this->auth)->assertStatus(422);
        $this->getJson('/api/v1/goals/'.$goal.$range.'&filters[country][]=FR', $this->auth)->assertOk();
        $this->getJson('/api/v1/goals/'.$goal.$range.'&filters[device][]=mobile', $this->auth)->assertForbidden()->assertJsonPath('error.details.required', 'devices');
        $this->getJson('/api/v1/goals/'.$goal.'?from=2026-10-26&to=2026-10-20', $this->auth)->assertStatus(422);
    }

    public function test_a_goal_of_another_site_is_a_404_identical_to_an_unknown_one(): void
    {
        $foreign = $this->goal(Website::factory()->create(), 'Foreign');
        $unknown = $this->getJson('/api/v1/goals/999999', $this->auth);
        $unknown->assertNotFound();
        $response = $this->getJson('/api/v1/goals/'.$foreign, $this->auth);
        $response->assertNotFound();
        $this->assertSame($unknown->getContent(), $response->getContent());
        $this->getJson('/api/v1/goals/abc', $this->auth)->assertNotFound();
    }

    public function test_goals_and_funnels_need_their_own_scope(): void
    {
        $goal = $this->goal($this->site);
        [, $secret] = ApiToken::issue($this->site, null, 'Narrow', ['overview'], 60, null);
        $narrow = ['Authorization' => 'Bearer '.$secret];
        $this->getJson('/api/v1/goals', $narrow)->assertForbidden()->assertJsonPath('error.details.required', 'goals');
        $this->getJson('/api/v1/goals/'.$goal, $narrow)->assertForbidden();
        $this->getJson('/api/v1/funnels', $narrow)->assertForbidden()->assertJsonPath('error.details.required', 'funnels');
        $this->getJson('/api/v1/funnels/1', $narrow)->assertForbidden();
    }

    private function funnel(): int
    {
        $this->actingAs($this->owner)->post('/sites/'.$this->site->id.'/funnels', ['name' => 'Signup flow', 'steps' => [['type' => 'path', 'value' => '/'], ['type' => 'event', 'value' => 'signup']]])->assertRedirect();

        return (int) DB::table('funnels')->where('website_id', $this->site->id)->value('id');
    }

    public function test_funnels_are_listed_with_their_steps(): void
    {
        $id = $this->funnel();
        DB::table('funnels')->insert(['website_id' => Website::factory()->create()->id, 'name' => 'Foreign funnel', 'created_at' => now(), 'updated_at' => now()]);
        $json = $this->getJson('/api/v1/funnels', $this->auth)->assertOk()->json();
        $this->assertCount(1, $json['data']);
        $this->assertSame(['id', 'name', 'archived', 'version', 'steps'], array_keys($json['data'][0]));
        $this->assertSame($id, $json['data'][0]['id']);
        $this->assertSame([['type' => 'path', 'value' => '/', 'label' => null], ['type' => 'event', 'value' => 'signup', 'label' => null]], $json['data'][0]['steps']);
    }

    public function test_a_funnel_that_was_never_computed_is_a_404_and_nothing_is_written(): void
    {
        $id = $this->funnel();
        $before = DB::table('journey_reports')->count();
        $inserts = 0;
        DB::listen(function ($query) use (&$inserts): void {
            if (preg_match('/^\s*(insert|replace)\b/i', $query->sql) && ! str_contains($query->sql, 'api_tokens')) {
                $inserts++;
            }
        });
        $response = $this->getJson('/api/v1/funnels/'.$id.'?from=2026-10-24&to=2026-10-26', $this->auth);
        $response->assertNotFound()->assertJsonPath('error.code', 'report_not_computed');
        $this->assertSame($before, DB::table('journey_reports')->count());
        $this->assertSame(0, $inserts);
        $this->getJson('/api/v1/funnels/999999', $this->auth)->assertNotFound()->assertJsonPath('error.code', 'not_found');
    }

    public function test_a_computed_funnel_is_read_without_exposing_internals(): void
    {
        $id = $this->funnel();
        $this->event('private-session-a', '10:00:00');
        $this->event('private-session-a', '10:05:00', 2, 'signup');
        $this->event('private-session-b', '11:00:00');
        $range = '?from=2026-10-24&to=2026-10-26';
        $this->get('/sites/'.$this->site->id.'/funnels/'.$id.$range)->assertOk();
        for ($i = 0; $i < 20 && app(JourneyReports::class)->tick(); $i++) {
            // Run the asynchronous calculation to its end.
        }
        $this->assertSame('ready', DB::table('journey_reports')->value('status'));

        $response = $this->getJson('/api/v1/funnels/'.$id.$range, $this->auth)->assertOk();
        $json = $response->json();
        $this->assertSame(['funnel', 'period', 'result', 'computed_at'], array_keys($json['data']));
        $this->assertSame(['id', 'name', 'archived', 'version', 'steps'], array_keys($json['data']['funnel']));
        $this->assertSame(['entries', 'conversions', 'pending', 'conversion_rate', 'steps', 'coverage'], array_keys($json['data']['result']));
        $this->assertSame(2, $json['data']['result']['entries']);
        $this->assertSame(1, $json['data']['result']['conversions']);
        $this->assertSame([2, 1], array_column($json['data']['result']['steps'], 'count'));
        $this->assertNotNull($json['data']['computed_at']);
        $content = $response->getContent();
        foreach (['cache_key', 'snapshot_id', 'session_key', 'cursor', '"state"', '"payload"', 'private-session'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $content);
        }
        // A different period, version or set of filters was not computed.
        $this->getJson('/api/v1/funnels/'.$id.'?from=2026-10-20&to=2026-10-26', $this->auth)->assertNotFound()->assertJsonPath('error.code', 'report_not_computed');
        $this->getJson('/api/v1/funnels/'.$id.$range.'&version=1&filters[country][]=FR', $this->auth)->assertNotFound()->assertJsonPath('error.code', 'report_not_computed');
        $this->getJson('/api/v1/funnels/'.$id.$range.'&version=7', $this->auth)->assertNotFound()->assertJsonPath('error.code', 'not_found');
        $this->getJson('/api/v1/funnels/'.$id.$range.'&filters[device][]=mobile', $this->auth)->assertForbidden();
    }

    public function test_the_latest_computed_report_wins(): void
    {
        $id = $this->funnel();
        $payload = ['kind' => 'funnel', 'funnel_id' => $id, 'version' => 1, 'steps' => [['type' => 'path', 'value' => '/']], 'from' => '2026-10-24 00:00:00', 'to' => '2026-10-27 00:00:00', 'filters' => []];
        $result = fn (int $entries) => json_encode(['entries' => $entries, 'conversions' => 0, 'pending' => 0, 'conversion_rate' => null, 'steps' => [], 'nodes' => [], 'links' => [], 'coverage' => ['status' => 'available', 'excluded_sessions' => 0, 'raw_retention_days' => 90, 'message' => 'm']]);
        foreach ([[11, 'old-a', '2026-10-26 10:00:00'], [22, 'new-b', '2026-10-26 11:00:00']] as [$entries, $key, $at]) {
            DB::table('journey_reports')->insert(['website_id' => $this->site->id, 'cache_key' => str_pad($key, 64, '0'), 'status' => 'ready', 'snapshot_id' => 1, 'payload' => json_encode($payload), 'result' => $result($entries), 'created_at' => $at, 'updated_at' => $at]);
        }
        // Another site's report and an unfinished one are never used.
        DB::table('journey_reports')->insert(['website_id' => Website::factory()->create()->id, 'cache_key' => str_pad('foreign', 64, '0'), 'status' => 'ready', 'snapshot_id' => 1, 'payload' => json_encode($payload), 'result' => $result(99), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('journey_reports')->insert(['website_id' => $this->site->id, 'cache_key' => str_pad('scanning', 64, '0'), 'status' => 'scanning', 'snapshot_id' => 1, 'payload' => json_encode($payload), 'result' => $result(77), 'created_at' => now(), 'updated_at' => now()]);

        $this->getJson('/api/v1/funnels/'.$id.'?from=2026-10-24&to=2026-10-26', $this->auth)->assertOk()->assertJsonPath('data.result.entries', 22);
    }
}
