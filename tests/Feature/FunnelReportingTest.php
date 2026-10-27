<?php

namespace Tests\Feature;

use App\Analytics\Reporting\JourneyReports;
use App\Installation\InstallationState;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FunnelReportingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
    }

    private function definition(): array
    {
        return ['name' => 'Signup', 'steps' => [['type' => 'path', 'value' => '/'], ['type' => 'event', 'value' => 'signup']]];
    }

    public function test_owners_version_funnels_and_viewers_cannot_modify_them(): void
    {
        $site = Website::factory()->create(['tracking_mode' => 'sessions']);
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $site->members()->attach($owner, ['role' => 'owner']);
        $site->members()->attach($viewer, ['role' => 'viewer']);
        $this->actingAs($owner)->post('/sites/'.$site->id.'/funnels', $this->definition())->assertRedirect();
        $id = DB::table('funnels')->value('id');
        $this->put('/sites/'.$site->id.'/funnels/'.$id, $this->definition())->assertRedirect();
        $this->assertDatabaseCount('funnel_versions', 2);
        $this->actingAs($viewer)->post('/sites/'.$site->id.'/funnels', $this->definition())->assertForbidden();
        $this->get('/sites/'.$site->id.'/funnels/'.$id)->assertOk();
        $this->actingAs(User::factory()->create(['is_admin' => true]))->get('/sites/'.$site->id.'/funnels/'.$id)->assertForbidden();
    }

    public function test_report_jobs_resume_across_small_batches_and_hide_session_keys(): void
    {
        $this->travelTo(now('UTC')->setDate(2026, 10, 26)->setTime(12, 0));
        $site = Website::factory()->create(['tracking_mode' => 'sessions']);
        foreach ([['2026-10-24 10:00:00', 1, null], ['2026-10-24 10:05:00', 2, 'signup']] as [$time,$type,$event]) {
            DB::table('events')->insert(['website_id' => $site->id, 'received_at' => $time, 'path' => '/', 'event_type' => $type, 'event_name' => $event, 'session_key' => 'private-session-key', 'consent_given' => true]);
        }
        $service = app(JourneyReports::class);
        $job = $service->request($site, ['kind' => 'funnel', 'steps' => $this->definition()['steps'], 'from' => '2026-10-24 00:00:00', 'to' => '2026-10-25 00:00:00', 'filters' => []]);
        for ($i = 0; $i < 10; $i++) {
            if (! $service->tick(1)) {
                break;
            }
        }
        $status = $service->publicStatus(DB::table('journey_reports')->find($job->id));
        $this->assertSame('ready', $status['status']);
        $this->assertSame(1, $status['data']['conversions']);
        $this->assertStringNotContainsString('private-session-key', json_encode($status));
        $this->assertDatabaseCount('journey_report_sessions', 0);
    }

    public function test_identifier_free_events_and_truncated_sessions_cannot_manufacture_conversions(): void
    {
        $this->travelTo(now('UTC')->setDate(2026, 10, 26)->setTime(12, 0));
        $site = Website::factory()->create(['tracking_mode' => 'sessions']);
        foreach ([null, 'truncated'] as $session) {
            DB::table('events')->insert(['website_id' => $site->id, 'received_at' => '2026-10-24 10:00:00', 'path' => '/', 'session_key' => $session, 'consent_given' => true]);
        }
        DB::table('measured_sessions')->insert(['website_id' => $site->id, 'session_key' => 'truncated', 'first_seen_at' => '2026-10-24 09:00:00', 'last_seen_at' => '2026-10-24 10:00:00']);
        $service = app(JourneyReports::class);
        $job = $service->request($site, ['kind' => 'funnel', 'steps' => $this->definition()['steps'], 'from' => '2026-10-24 00:00:00', 'to' => '2026-10-25 00:00:00', 'filters' => []]);
        for ($i = 0; $i < 10 && $service->tick(1); $i++) {
        }
        $status = $service->publicStatus(DB::table('journey_reports')->find($job->id));
        $this->assertSame(0, $status['data']['entries']);
        $this->assertSame(1, $status['excluded_sessions']);
        $this->assertNull($status['data']['conversion_rate']);
    }

    public function test_precomputed_demo_reports_survive_the_normal_cache_window(): void
    {
        $site = Website::factory()->create(['is_demo' => true, 'tracking_mode' => 'sessions']);
        $service = app(JourneyReports::class);
        $payload = ['kind' => 'funnel', 'steps' => $this->definition()['steps'], 'from' => '2026-10-24 00:00:00', 'to' => '2026-10-25 00:00:00', 'filters' => []];
        $first = $service->request($site, $payload);
        while ($service->tick(10)) {
        }
        $this->travel(6)->minutes();
        $this->assertSame($first->id, $service->request($site, $payload)->id);
        DB::table('events')->insert(['website_id' => $site->id, 'received_at' => now('UTC'), 'path' => '/', 'consent_given' => true]);
        $this->assertNotSame($first->id, $service->request($site, $payload)->id);
    }
}
