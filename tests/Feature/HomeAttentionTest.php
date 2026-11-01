<?php

namespace Tests\Feature;

use App\Analytics\InstanceHealth;
use App\Installation\InstallationState;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HomeAttentionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
    }

    private function health(): InstanceHealth
    {
        return app(InstanceHealth::class);
    }

    private function codes(): array
    {
        return array_column($this->health()->attention(), 'code');
    }

    public function test_a_scheduler_that_never_ran_is_reported(): void
    {
        $this->assertSame(['scheduler'], $this->codes());
        $this->assertStringContainsString('never', $this->health()->attention()[0]['message']);
    }

    public function test_a_recent_heartbeat_means_nothing_to_report(): void
    {
        $this->health()->put('heartbeat', now('UTC')->toIso8601String());

        $this->assertSame([], $this->health()->attention());
    }

    public function test_a_stale_heartbeat_says_for_how_long_the_scheduler_has_been_silent(): void
    {
        $this->health()->put('heartbeat', now('UTC')->subMinutes(25)->toIso8601String());

        $attention = $this->health()->attention();

        $this->assertSame(['scheduler'], array_column($attention, 'code'));
        $this->assertStringContainsString('25 minutes', $attention[0]['message']);
    }

    public function test_events_waiting_too_long_are_reported_when_the_scheduler_is_alive(): void
    {
        $this->health()->put('heartbeat', now('UTC')->toIso8601String());
        $site = Website::factory()->create();
        DB::table('events')->insert(['website_id' => $site->id, 'received_at' => now('UTC')->subMinutes(40), 'event_type' => 1, 'path' => '/', 'consent_given' => true]);

        $this->assertSame(['backlog'], $this->codes());
    }

    public function test_a_silent_scheduler_is_the_only_message_since_it_explains_the_backlog(): void
    {
        $site = Website::factory()->create();
        DB::table('events')->insert(['website_id' => $site->id, 'received_at' => now('UTC')->subMinutes(40), 'event_type' => 1, 'path' => '/', 'consent_given' => true]);

        $this->assertSame(['scheduler'], $this->codes());
    }

    public function test_the_home_page_shows_it_to_administrators_with_the_way_to_the_health_page_and_hides_it_from_members(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('attention.0.code', 'scheduler')
            ->where('attention.0.link', '/health'));
        $this->actingAs(User::factory()->create())->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('attention', []));
    }

    public function test_sites_that_never_received_an_event_point_to_their_tracking_setup(): void
    {
        $user = User::factory()->create();
        $waiting = Website::factory()->create(['name' => 'Waiting']);
        $waiting->members()->attach($user, ['role' => 'owner']);

        $this->actingAs($user)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('sites.data.0.tracking_url', '/sites/'.$waiting->id.'/tracking')
            ->where('sites.data.0.first_collected_at', null));
    }
}
