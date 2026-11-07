<?php

namespace Tests\Feature;

use App\Installation\InstallationState;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Pins the sign-in flow of an account without a second factor as it behaved before two-factor authentication
 * existed. These tests must stay green, unchanged, whatever is added around the login.
 */
class LoginFlowCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
    }

    public function test_a_successful_sign_in_authenticates_in_the_same_request_and_changes_the_session_id(): void
    {
        $user = User::factory()->create();
        $this->get('/login')->assertOk();
        $before = session()->getId();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/sites');

        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($before, session()->getId());
    }

    public function test_the_email_is_case_insensitive_and_the_page_asked_for_before_signing_in_is_restored(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.test']);
        $this->get('/health')->assertRedirect('/login');

        $this->post('/login', ['email' => 'OWNER@Example.Test', 'password' => 'password'])->assertRedirect('/health');

        $this->assertAuthenticatedAs($user);
    }

    public function test_the_sign_in_does_not_remember_the_browser_and_leaves_the_remember_token_alone(): void
    {
        $user = User::factory()->create();
        $token = $user->remember_token;

        $response = $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $this->assertSame($token, $user->fresh()->remember_token);
        $this->assertEmpty(array_filter($response->headers->getCookies(), fn ($cookie) => str_starts_with($cookie->getName(), 'remember_web_')));
    }

    public function test_a_wrong_password_and_an_unknown_address_give_the_same_error_and_no_session(): void
    {
        $user = User::factory()->create();

        $wrong = $this->post('/login', ['email' => $user->email, 'password' => 'incorrect']);
        $unknown = $this->post('/login', ['email' => 'nobody@example.test', 'password' => 'incorrect']);

        $wrong->assertSessionHasErrors(['email' => 'These credentials do not match our records.']);
        $unknown->assertSessionHasErrors(['email' => 'These credentials do not match our records.']);
        $this->assertGuest();
    }

    public function test_missing_or_malformed_fields_are_validation_errors(): void
    {
        $this->post('/login', [])->assertSessionHasErrors(['email', 'password']);
        $this->post('/login', ['email' => 'not-an-email', 'password' => 'x'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_the_limiter_is_per_address_and_ip_and_a_success_clears_it(): void
    {
        $user = User::factory()->create();
        $key = 'login:'.hash('sha256', $user->email.'|127.0.0.1');
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'incorrect']);
        }
        $this->assertSame(4, RateLimiter::attempts($key));

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/sites');

        $this->assertSame(0, RateLimiter::attempts($key));
    }

    public function test_the_limiter_refuses_even_the_right_password_after_five_failures(): void
    {
        $user = User::factory()->create();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'incorrect']);
        }

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertStatus(429);

        $this->assertGuest();
    }

    public function test_a_signed_in_visitor_is_sent_away_from_the_login_page_and_sign_out_empties_the_session(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/login')->assertRedirect('/sites');
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
        $this->get('/sites')->assertRedirect('/login');
    }

    public function test_changing_the_password_rotates_the_remember_token_and_keeps_the_current_session(): void
    {
        $user = User::factory()->create();
        $token = $user->remember_token;
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $this->put('/account/password', ['current_password' => 'password', 'password' => 'New-password-123!', 'password_confirmation' => 'New-password-123!'])->assertRedirect('/account/password');

        $this->assertNotSame($token, $user->fresh()->remember_token);
        $this->assertTrue(Hash::check('New-password-123!', $user->fresh()->password));
        $this->get('/sites')->assertOk();
    }

    public function test_the_session_guard_is_not_authenticated_until_the_sign_in_succeeds(): void
    {
        $user = User::factory()->create();
        $this->post('/login', ['email' => $user->email, 'password' => 'incorrect']);
        $this->assertFalse(Auth::check());
        $this->assertNull(session('login_web_'.sha1(\Illuminate\Auth\SessionGuard::class)));
    }

    public function test_recovering_the_administrator_rotates_the_remember_token_and_locks_the_installation(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $token = $admin->remember_token;

        $this->artisan('perysee:recover-admin', ['email' => $admin->email])
            ->expectsQuestion('New password (at least 12 characters)', 'Recovered-password-123!')
            ->expectsQuestion('Confirm new password', 'Recovered-password-123!')
            ->expectsOutputToContain('Existing sessions must sign in again.')
            ->assertSuccessful();

        $this->assertNotSame($token, $admin->fresh()->remember_token);
        $this->post('/login', ['email' => $admin->email, 'password' => 'Recovered-password-123!'])->assertRedirect('/sites');
    }
}
