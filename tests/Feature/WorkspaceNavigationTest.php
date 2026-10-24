<?php

namespace Tests\Feature;

use App\Installation\InstallationState;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
    }

    public function test_global_pages_have_workspace_navigation_and_pixels_placeholder(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get('/sites')->assertOk()->assertSee('data-navigation="global"', false)->assertSee('Instance settings')->assertSee('Password');
        $this->get('/pixels')->assertOk()->assertSee('Pixels')->assertSee('Coming soon');
        $this->get('/dashboard')->assertOk()->assertSee('Coming soon');
        $this->get('/settings')->assertOk()->assertSee('Instance settings')->assertSee('Current configuration');
    }

    public function test_instance_settings_are_restricted_to_administrators(): void
    {
        $this->actingAs(User::factory()->create())->get('/settings')->assertForbidden();
        $this->get('/sites')->assertOk()->assertDontSee('Instance settings');
    }

    public function test_site_context_separates_overview_tracking_and_future_pages(): void
    {
        $user = User::factory()->create();
        $site = Website::factory()->create();
        $site->members()->attach($user, ['role' => 'owner']);
        $this->actingAs($user)->get('/sites/'.$site->id)->assertOk()->assertSee('data-navigation="site"', false)->assertSee('Overview')->assertSee('Tracking setup')->assertDontSee('data-navigation="global"', false)->assertDontSee('<pre class="snippet">', false);
        $this->get('/sites/'.$site->id.'/tracking')->assertOk()->assertSee('Install Lueur')->assertSee('data-site=');
        foreach (['events', 'sessions', 'realtime', 'pixels'] as $section) {
            $this->get('/sites/'.$site->id.'/'.$section)->assertOk()->assertSee('Coming soon')->assertSee($site->name);
        }
    }

    public function test_site_sections_preserve_membership_and_viewer_permissions(): void
    {
        $site = Website::factory()->create();
        $viewer = User::factory()->create();
        $site->members()->attach($viewer, ['role' => 'viewer']);
        $this->actingAs($viewer)->get('/sites/'.$site->id.'/tracking')->assertOk()->assertDontSee('Site settings');
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        foreach (['tracking', 'events', 'sessions', 'realtime', 'pixels'] as $section) {
            $this->get('/sites/'.$site->id.'/'.$section)->assertForbidden();
        }
    }

    public function test_search_and_site_switcher_only_expose_the_users_sites(): void
    {
        $owner = User::factory()->create();
        $alpha = Website::factory()->create(['name' => 'Alpha journal']);
        $beta = Website::factory()->create(['name' => 'Beta journal']);
        $hidden = Website::factory()->create(['name' => 'Private client']);
        $alpha->members()->attach($owner, ['role' => 'owner']);
        $beta->members()->attach($owner, ['role' => 'owner']);
        $this->actingAs($owner)->get('/sites?q=Alpha')->assertOk()->assertSee('Alpha journal')->assertDontSee('Beta journal')->assertDontSee($hidden->name);
        $this->get('/sites/'.$alpha->id)->assertOk()->assertSee('Beta journal')->assertDontSee($hidden->name);
    }
}
