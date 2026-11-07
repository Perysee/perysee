<?php

namespace Tests\Feature;

use App\Installation\InstallationState;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WorkspaceNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
    }

    public function test_workspace_shares_documentation_and_repository_urls(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get('/sites')->assertOk()->assertInertia(fn (Assert $page) => $page->where('docsUrl', rtrim(config('perysee.docs_url'), '/'))->where('repositoryUrl', 'https://github.com/Perysee/perysee'));
    }

    public function test_global_pages_have_workspace_navigation_and_pixels_placeholder(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get('/sites')->assertOk()->assertInertia(fn (Assert $page) => $page->where('screen', 'sites.index')->where('auth.is_admin', true)->where('links.settings', '/settings')->where('links', fn ($links) => $links['password.edit'] === '/account/password'));
        $this->get('/pixels')->assertOk()->assertInertia(fn (Assert $page) => $page->where('screen', 'placeholder')->where('title', 'Pixels'));
        $this->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page->where('screen', 'dashboard'));
        $this->get('/settings')->assertOk()->assertInertia(fn (Assert $page) => $page->where('screen', 'settings')->has('settings'));
    }

    public function test_instance_settings_are_restricted_to_administrators(): void
    {
        $this->actingAs(User::factory()->create())->get('/settings')->assertForbidden();
        $this->get('/sites')->assertOk()->assertInertia(fn (Assert $page) => $page->where('auth.is_admin', false));
    }

    public function test_site_context_separates_overview_tracking_and_future_pages(): void
    {
        $user = User::factory()->create();
        $site = Website::factory()->create();
        $site->members()->attach($user, ['role' => 'owner']);
        $this->actingAs($user)->get('/sites/'.$site->id)->assertOk()->assertInertia(fn (Assert $page) => $page->where('screen', 'sites.show')->where('website.id', $site->id)->missing('snippet'));
        $this->get('/sites/'.$site->id.'/tracking')->assertOk()->assertInertia(fn (Assert $page) => $page->where('screen', 'sites.tracking')->where('snippet', fn (string $snippet) => str_contains($snippet, 'data-site="'.$site->public_id.'"')));
        foreach (['events', 'sessions', 'realtime', 'pixels'] as $section) {
            $this->get('/sites/'.$site->id.'/'.$section)->assertOk()->assertInertia(fn (Assert $page) => $page->where('website.id', $site->id)->where('screen', $section === 'pixels' ? 'placeholder' : 'sites.show'));
        }
    }

    public function test_site_sections_preserve_membership_and_viewer_permissions(): void
    {
        $site = Website::factory()->create();
        $viewer = User::factory()->create();
        $site->members()->attach($viewer, ['role' => 'viewer']);
        $this->actingAs($viewer)->get('/sites/'.$site->id.'/tracking')->assertOk()->assertInertia(fn (Assert $page) => $page->where('canUpdate', false));
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
        $this->actingAs($owner)->get('/sites?q=Alpha')->assertOk()->assertInertia(fn (Assert $page) => $page->has('websites.data', 1)->where('websites.data.0.id', $alpha->id)->has('navigation', 2))->assertDontSee($hidden->name);
        $this->get('/sites/'.$alpha->id)->assertOk()->assertSee('Beta journal')->assertDontSee($hidden->name);
    }
}
