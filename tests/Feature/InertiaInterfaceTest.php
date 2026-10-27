<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Installation\InstallationState;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InertiaInterfaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_interface_shares_only_accessible_sites_and_no_authentication_secrets(): void
    {
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
        $user = User::factory()->create(['is_admin' => true]);
        $site = Website::factory()->create();
        $site->members()->attach($user, ['role' => 'viewer']);
        Website::factory()->create(['name' => 'Forbidden client']);
        $this->actingAs($user)->get('/sites/'.$site->id.'?period=today')->assertInertia(fn (Assert $page) => $page->component('Workspace')->where('screen', 'sites.show')->where('canUpdate', false)->where('report.period.from', now($site->timezone)->toDateString())->has('navigation', 1)->missing('auth.password')->missing('auth.remember_token'));
        $this->get('/sites')->assertInertia(fn (Assert $page) => $page->where('screen', 'sites.index')->has('websites.data', 1));
        $this->get('/health')->assertInertia(fn (Assert $page) => $page->where('screen', 'health'));
    }

    public function test_component_lookup_uses_the_exact_page_directory(): void
    {
        $this->assertSame([resource_path('js/Pages')], config('inertia.pages.paths'));
        $this->assertSame(['vue'], config('inertia.pages.extensions'));
    }

    public function test_login_renders_without_server_side_rendering(): void
    {
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
        $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('screen', 'auth.login')->where('auth', null));
        $this->assertFalse(config('inertia.ssr.enabled'));
    }

    public function test_calendar_uses_the_sites_today_and_rejects_future_or_excessive_ranges(): void
    {
        $this->travelTo(Carbon::parse('2026-10-25 00:30:00', 'UTC'));
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
        $site = Website::factory()->create(['timezone' => 'America/Los_Angeles']);
        $user = User::factory()->create();
        $site->members()->attach($user, ['role' => 'viewer']);
        $this->actingAs($user)->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(Request::create('/')) ?? ''])->get('/sites/'.$site->id.'?period=today')
            ->assertOk()->assertHeader('X-Inertia', 'true')->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('props.today', '2026-10-24')->assertJsonPath('props.period.from', '2026-10-24');
        $this->get('/sites/'.$site->id.'?from=2026-10-24&to=2026-10-25')->assertSessionHasErrors('from');
        $this->get('/sites/'.$site->id.'?from=2020-01-01&to=2026-10-24')->assertSessionHasErrors('from');
        $this->get('/sites/'.$site->id.'?from=2026-10-24&to=2026-10-23')->assertSessionHasErrors('to');
    }

    public function test_inertia_forms_receive_server_validation_errors(): void
    {
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
        $this->actingAs(User::factory()->create())->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(Request::create('/')) ?? ''])->from('/sites/create')
            ->post('/sites', [])->assertRedirect('/sites/create')->assertSessionHasErrors(['name', 'domain', 'timezone']);
        $this->withCookie(config('session.cookie'), session()->getId())->get('/sites/create')->assertJsonPath('props.screen', 'sites.form')
            ->assertJsonPath('props.errors.name', __('validation.required', ['attribute' => 'name']))
            ->assertJsonMissingPath('props.auth.password');
        $this->assertDatabaseCount('websites', 0);
    }
}
