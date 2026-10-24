<?php

namespace Tests\Feature;

use App\Installation\InstallationState;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebsiteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
    }

    private function payload(): array
    {
        return ['name' => 'My blog', 'domain' => 'Example.COM', 'allowed_domains' => "www.example.com\npreview.example.com", 'timezone' => 'Europe/Paris'];
    }

    public function test_a_user_can_create_and_update_a_site_with_a_stable_public_identifier(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/sites', $this->payload())->assertSessionHasNoErrors()->assertRedirect();
        $website = Website::firstOrFail();
        $publicId = $website->public_id;
        $this->assertSame('example.com', $website->domain);
        $this->assertContains('example.com', $website->allowed_domains);
        $this->assertSame('owner', $website->members()->first()->pivot->role);
        $this->get('/sites/'.$website->id)->assertOk()->assertSee('Install Lueur');
        $this->put('/sites/'.$website->id, array_merge($this->payload(), ['name' => 'Updated', 'public_id' => 'forged']))->assertSessionHasNoErrors();
        $this->assertSame($publicId, $website->fresh()->public_id);
        $this->assertSame('Updated', $website->fresh()->name);
    }

    public function test_non_members_including_instance_admin_cannot_access_another_site(): void
    {
        $site = Website::factory()->create();
        $owner = User::factory()->create();
        $site->members()->attach($owner, ['role' => 'owner']);
        $outsider = User::factory()->create(['is_admin' => true]);
        $this->actingAs($outsider)->get('/sites')->assertOk()->assertDontSee($site->name);
        $this->get('/sites/'.$site->id)->assertForbidden();
        $this->get('/sites/'.$site->id.'/edit')->assertForbidden();
        $this->put('/sites/'.$site->id, $this->payload())->assertForbidden();
    }

    public function test_viewer_can_read_but_cannot_change_a_site(): void
    {
        $site = Website::factory()->create();
        $viewer = User::factory()->create();
        $site->members()->attach($viewer, ['role' => 'viewer']);
        $this->actingAs($viewer)->get('/sites/'.$site->id)->assertOk()->assertDontSee('Edit site');
        $this->get('/sites/'.$site->id.'/edit')->assertForbidden();
        $this->put('/sites/'.$site->id, $this->payload())->assertForbidden();
        $this->delete('/sites/'.$site->id)->assertMethodNotAllowed();
    }

    public function test_invalid_domains_and_timezones_are_rejected(): void
    {
        $this->actingAs(User::factory()->create())->post('/sites', array_merge($this->payload(), ['domain' => 'https://example.com/path', 'allowed_domains' => '*.example.com', 'timezone' => 'not-a-zone']))->assertSessionHasErrors(['domain', 'allowed_domains.0', 'timezone']);
        $this->assertDatabaseCount('websites', 0);
    }

    public function test_site_names_are_escaped_and_payload_cannot_grant_admin_access(): void
    {
        $user = User::factory()->create();
        $name = '<script>alert("xss")</script>';
        $this->actingAs($user)->post('/sites', array_merge($this->payload(), ['name' => $name, 'is_admin' => true, 'members' => [999]]))->assertSessionHasNoErrors();
        $site = Website::firstOrFail();
        $this->get('/sites/'.$site->id)->assertOk()->assertSee($name)->assertDontSee($name, false);
        $this->assertFalse($user->fresh()->is_admin);
        $this->assertSame(1, $site->members()->count());
    }

    public function test_owner_can_select_tracking_mode_and_consent_policy(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/sites', array_merge($this->payload(), ['tracking_mode' => 'sessions', 'consent_required' => '0']))->assertSessionHasNoErrors();
        $site = Website::firstOrFail();
        $this->assertSame('sessions', $site->tracking_mode);
        $this->assertFalse($site->consent_required);
        $this->get('/sites/'.$site->id.'/edit')->assertOk()->assertSee('Consent not required');
        $this->put('/sites/'.$site->id, array_merge($this->payload(), ['tracking_mode' => 'invalid', 'consent_required' => 'invalid']))->assertSessionHasErrors(['tracking_mode', 'consent_required']);
    }
}
