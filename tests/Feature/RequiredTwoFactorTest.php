<?php

namespace Tests\Feature;

use App\Installation\InstallationState;
use App\Models\ShareLink;
use App\Models\SiteInvitation;
use App\Models\User;
use App\Models\Website;
use App\Support\InstanceSecurity;
use App\Support\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithTwoFactor;
use Tests\TestCase;

class RequiredTwoFactorTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
    }

    private function require(): void
    {
        app(InstanceSecurity::class)->requireTwoFactor(true);
    }

    public function test_without_the_option_nothing_changes_for_an_account_without_a_second_factor(): void
    {
        $this->actingAs(User::factory()->create())->get('/sites')->assertOk();
    }

    public function test_with_the_option_an_account_without_a_second_factor_is_sent_to_set_it_up_from_every_page(): void
    {
        $this->require();
        $user = User::factory()->create(['is_admin' => true]);
        $site = Website::factory()->create();
        $site->members()->attach($user, ['role' => 'owner']);

        foreach (['/sites', '/dashboard', '/sites/'.$site->id, '/health', '/settings/mail', '/account/password', '/sites/'.$site->id.'/team'] as $path) {
            $this->actingAs($user)->get($path)->assertRedirect('/account/security');
        }
        $this->actingAs($user)->post('/sites', ['name' => 'Injected'])->assertRedirect('/account/security');
        $this->actingAs($user)->getJson('/api/sites/'.$site->id.'/overview')->assertForbidden();
    }

    public function test_the_setup_page_and_sign_out_stay_reachable_and_the_page_says_why(): void
    {
        $this->require();
        $user = User::factory()->create();

        $this->actingAs($user)->get('/account/security')->assertOk()->assertInertia(fn (Assert $page) => $page->where('twoFactor.required', true)->where('twoFactor.enabled', false));
        $this->post('/account/security/two-factor', ['current_password' => 'password'])->assertRedirect('/account/security');
        $this->get('/account/security')->assertOk();
        $this->post('/logout')->assertRedirect('/login');
    }

    public function test_once_enrolled_the_account_works_normally(): void
    {
        $this->require();
        $user = User::factory()->create();
        $this->actingAs($user)->get('/sites')->assertRedirect('/account/security');
        $this->enableTwoFactor($user);

        $this->get('/sites')->assertOk();
    }

    public function test_an_account_cannot_turn_its_own_second_factor_off_while_it_is_required(): void
    {
        $user = User::factory()->create();
        $this->enableTwoFactor($user);
        $this->require();

        $this->actingAs($user)->delete('/account/security/two-factor', ['current_password' => 'password'])->assertSessionHasErrors('current_password');
        $this->assertTrue(app(TwoFactor::class)->enabled($user));
    }

    public function test_an_administrator_cannot_require_it_before_having_it_and_then_can(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->put('/settings/security', ['require_two_factor' => true])->assertSessionHasErrors('require_two_factor');
        $this->assertFalse(app(InstanceSecurity::class)->twoFactorRequired());

        $this->enableTwoFactor($admin);
        $this->put('/settings/security', ['require_two_factor' => true])->assertRedirect('/settings/security');
        $this->assertTrue(app(InstanceSecurity::class)->twoFactorRequired());

        $this->put('/settings/security', ['require_two_factor' => false])->assertRedirect('/settings/security');
        $this->assertFalse(app(InstanceSecurity::class)->twoFactorRequired());
    }

    public function test_only_an_administrator_can_change_it(): void
    {
        $user = User::factory()->create();
        $this->enableTwoFactor($user);

        $this->actingAs($user)->put('/settings/security', ['require_two_factor' => true])->assertForbidden();

        $this->assertFalse(app(InstanceSecurity::class)->twoFactorRequired());
    }

    public function test_a_change_applies_at_once_despite_the_cache(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->enableTwoFactor($admin);
        $plain = User::factory()->create();
        $this->actingAs($plain)->get('/sites')->assertOk();
        Cache::flush();

        $this->actingAs($admin)->put('/settings/security', ['require_two_factor' => true]);
        $this->actingAs($plain)->get('/sites')->assertRedirect('/account/security');
    }

    public function test_the_public_pages_are_not_affected(): void
    {
        $this->require();
        $site = Website::factory()->create();
        $owner = User::factory()->create();
        $site->members()->attach($owner, ['role' => 'owner']);
        [, $token] = ShareLink::issue($site, $owner, '', ['overview'], null, null);

        $this->get('/s/'.$token)->assertSuccessful();
        $this->get('/login')->assertOk();
        $this->get('/up')->assertOk();
    }

    public function test_a_person_who_just_accepted_an_invitation_is_sent_to_set_it_up(): void
    {
        $this->require();
        $site = Website::factory()->create();
        $owner = User::factory()->create();
        $site->members()->attach($owner, ['role' => 'owner']);
        [, $token] = SiteInvitation::issue($site, 'invitee@example.com', 'viewer', $owner);

        $this->post('/invitations/'.$token, ['name' => 'Invitee', 'password' => 'a-long-enough-password'])->assertRedirect('/sites/'.$site->id);

        $this->get('/sites/'.$site->id)->assertRedirect('/account/security');
    }

    public function test_the_challenge_still_applies_to_enrolled_accounts_when_it_is_required(): void
    {
        $this->require();
        $user = User::factory()->create();
        $this->enableTwoFactor($user);

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/login/two-factor');
        $this->assertGuest();
    }
}
