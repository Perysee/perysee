<?php

namespace Tests\Feature;

use App\Installation\InstallationState;
use App\Models\AccessLogEntry;
use App\Models\User;
use App\Models\UserTwoFactor;
use App\Support\TwoFactor;
use Illuminate\Auth\SessionGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\InteractsWithTwoFactor;
use Tests\Support\KeepsSessionCookie;
use Tests\TestCase;

class TwoFactorLoginTest extends TestCase
{
    use InteractsWithTwoFactor, KeepsSessionCookie, RefreshDatabase;

    private string $secret;

    private array $codes;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
        $this->user = User::factory()->create();
        [$this->secret, $this->codes] = $this->enableTwoFactor($this->user);
    }

    private function passPassword(): void
    {
        $this->post('/login', ['email' => $this->user->email, 'password' => 'password'])->assertRedirect('/login/two-factor');
    }

    public function test_the_totp_implementation_matches_the_rfc_6238_vector(): void
    {
        // RFC 6238, appendix B, SHA-1, T = 59 s (counter 1): 94287082, six digits keep the last ones.
        $google = new Google2FA;
        $google->setOneTimePasswordLength(8);
        $this->assertSame('94287082', $google->oathTotp('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 1));
        $google->setOneTimePasswordLength(6);
        $this->assertSame('287082', $google->oathTotp('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 1));
    }

    public function test_a_correct_password_alone_does_not_sign_in_and_opens_the_challenge(): void
    {
        $this->get('/login');
        $before = session()->getId();

        $this->browser()->post('/login', ['email' => $this->user->email, 'password' => 'password'])->assertRedirect('/login/two-factor');

        $this->assertNotSame($before, session()->getId(), 'The session id changes when the challenge opens.');
        $this->assertGuest();
        $this->assertNull(session('login_web_'.sha1(SessionGuard::class)));
        $this->get('/sites')->assertRedirect('/login');
        $this->get('/account/security')->assertRedirect('/login');
        $this->assertSame(0, DB::table('user_sessions')->count());
        $this->assertSame(0, AccessLogEntry::where('event', 'login.success')->count());
    }

    public function test_the_challenge_page_is_private_and_needs_a_password_first(): void
    {
        $this->get('/login/two-factor')->assertRedirect('/login');
        $this->post('/login/two-factor', ['code' => '123456'])->assertRedirect('/login');
        $this->passPassword();
        $this->get('/login/two-factor')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertInertia(fn (Assert $page) => $page->where('screen', 'auth.two-factor'));
    }

    public function test_the_right_code_signs_in_changes_the_session_id_and_restores_the_page_asked_for(): void
    {
        $this->get('/health')->assertRedirect('/login');
        $this->passPassword();
        $before = session()->getId();

        $this->browser()->post('/login/two-factor', ['code' => $this->totp($this->secret)])->assertRedirect('/health');

        $this->assertAuthenticatedAs($this->user);
        $this->assertNotSame($before, session()->getId());
        $this->assertNull(session('login.2fa'));
        $this->assertSame(1, DB::table('user_sessions')->where('user_id', $this->user->id)->count());
        $this->assertSame(1, AccessLogEntry::where('event', 'login.success')->where('user_id', $this->user->id)->count());
        $this->assertSame('totp', AccessLogEntry::where('event', 'login.success')->first()->context['method']);
    }

    public function test_spaces_in_the_code_are_ignored_and_the_neighbouring_steps_are_accepted(): void
    {
        $this->passPassword();
        $code = $this->totp($this->secret, -1);

        $this->post('/login/two-factor', ['code' => substr($code, 0, 3).' '.substr($code, 3)])->assertRedirect('/sites');
        $this->assertAuthenticatedAs($this->user);
    }

    public function test_a_wrong_code_leaves_the_visitor_signed_out_and_says_nothing_about_why(): void
    {
        $this->passPassword();

        $this->post('/login/two-factor', ['code' => '000000'])->assertSessionHasErrors(['code' => 'The code is not valid.']);
        $this->post('/login/two-factor', ['code' => 'WRONGWRONG'])->assertSessionHasErrors(['code' => 'The code is not valid.']);

        $this->assertGuest();
        $this->assertSame(2, AccessLogEntry::where('event', 'login.failed')->where('user_id', $this->user->id)->count());
        $this->assertSame('two_factor', AccessLogEntry::where('event', 'login.failed')->first()->context['stage']);
    }

    public function test_a_step_from_the_distant_past_or_future_is_refused(): void
    {
        $this->passPassword();

        $this->post('/login/two-factor', ['code' => $this->totp($this->secret, -2)])->assertSessionHasErrors('code');
        $this->post('/login/two-factor', ['code' => $this->totp($this->secret, 2)])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_the_challenge_expires_after_five_minutes(): void
    {
        $this->passPassword();
        $this->travel(5)->minutes();
        $this->travel(1)->seconds();

        $this->post('/login/two-factor', ['code' => $this->totp($this->secret)])->assertRedirect('/login')->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertNull(session('login.2fa'));
    }

    public function test_a_code_cannot_be_replayed_not_even_inside_its_window(): void
    {
        $this->passPassword();
        $code = $this->totp($this->secret);
        $this->post('/login/two-factor', ['code' => $code])->assertRedirect('/sites');
        $this->post('/logout');

        $this->passPassword();
        $this->post('/login/two-factor', ['code' => $code])->assertSessionHasErrors('code');
        // An older step than the accepted one is refused too, a newer one still works.
        $this->post('/login/two-factor', ['code' => $this->totp($this->secret, -1)])->assertSessionHasErrors('code');
        $this->post('/login/two-factor', ['code' => $this->totp($this->secret, 1)])->assertRedirect('/sites');
    }

    public function test_changing_the_password_closes_a_pending_challenge(): void
    {
        $this->passPassword();
        $this->user->forceFill(['password' => 'Another-password-123!'])->save();

        $this->post('/login/two-factor', ['code' => $this->totp($this->secret)])->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_a_wrong_password_with_a_second_factor_is_a_plain_failure(): void
    {
        $this->post('/login', ['email' => $this->user->email, 'password' => 'incorrect'])->assertSessionHasErrors(['email' => 'These credentials do not match our records.']);

        $this->assertGuest();
        $this->assertNull(session('login.2fa'));
        $this->assertSame('password', AccessLogEntry::where('event', 'login.failed')->first()->context['stage']);
    }

    public function test_five_wrong_codes_per_minute_close_the_challenge_and_require_the_password_again(): void
    {
        $this->passPassword();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login/two-factor', ['code' => '000000'])->assertSessionHasErrors('code');
        }

        $this->post('/login/two-factor', ['code' => $this->totp($this->secret)])->assertRedirect('/login')->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertNull(session('login.2fa'));
    }

    public function test_ten_wrong_codes_in_an_hour_block_the_account_from_every_address(): void
    {
        $this->passPassword();
        for ($attempt = 0; $attempt < 10; $attempt++) {
            RateLimiter::hit('2fa-user:'.$this->user->id, 3600);
        }

        $this->post('/login/two-factor', ['code' => $this->totp($this->secret)])->assertRedirect('/login');
        $this->assertGuest();
        $this->post('/login', ['email' => $this->user->email, 'password' => 'password'])->assertStatus(429);
        $this->assertGuest();
    }

    public function test_a_success_clears_the_counters(): void
    {
        $this->passPassword();
        $this->post('/login/two-factor', ['code' => '000000']);
        $this->assertSame(1, RateLimiter::attempts('2fa-user:'.$this->user->id));

        $this->post('/login/two-factor', ['code' => $this->totp($this->secret)])->assertRedirect('/sites');

        $this->assertSame(0, RateLimiter::attempts('2fa-user:'.$this->user->id));
    }

    public function test_a_recovery_code_signs_in_once_and_is_spent(): void
    {
        $this->passPassword();
        $this->post('/login/two-factor', ['code' => strtolower($this->codes[0])])->assertRedirect('/sites');
        $this->assertAuthenticatedAs($this->user);
        $this->assertSame(7, app(TwoFactor::class)->remainingRecoveryCodes($this->user));
        $this->assertSame('recovery_code', AccessLogEntry::where('event', 'login.success')->first()->context['method']);
        $this->post('/logout');

        $this->passPassword();
        $this->post('/login/two-factor', ['code' => $this->codes[0]])->assertSessionHasErrors('code');
        $this->assertGuest();
        $this->post('/login/two-factor', ['code' => str_replace('-', '', $this->codes[1])])->assertRedirect('/sites');
    }

    public function test_the_same_recovery_code_cannot_be_spent_twice(): void
    {
        $twoFactor = app(TwoFactor::class);

        $this->assertSame('recovery_code', $twoFactor->verifyChallenge($this->user, $this->codes[2]));
        $this->assertNull($twoFactor->verifyChallenge($this->user, $this->codes[2]));
    }

    public function test_a_recovery_code_of_another_account_is_worthless(): void
    {
        $other = User::factory()->create();
        [, $otherCodes] = $this->enableTwoFactor($other);

        $this->assertNull(app(TwoFactor::class)->verifyChallenge($this->user, $otherCodes[0]));
    }

    public function test_an_account_without_a_second_factor_is_unaffected_by_the_feature(): void
    {
        $plain = User::factory()->create();

        $this->post('/login', ['email' => $plain->email, 'password' => 'password'])->assertRedirect('/sites');

        $this->assertAuthenticatedAs($plain);
        $this->assertNull(session('login.2fa'));
    }

    public function test_an_unconfirmed_enrolment_does_not_ask_for_a_code(): void
    {
        $other = User::factory()->create();
        app(TwoFactor::class)->begin($other);

        $this->post('/login', ['email' => $other->email, 'password' => 'password'])->assertRedirect('/sites');

        $this->assertAuthenticatedAs($other);
    }

    public function test_the_challenge_cannot_be_reached_while_signed_in(): void
    {
        $this->actingAs($this->user)->get('/login/two-factor')->assertRedirect('/sites');
    }

    public function test_the_second_step_needs_the_csrf_token(): void
    {
        $this->app->instance('env', 'local');
        $this->post('/login/two-factor', ['code' => '123456'])->assertStatus(419);
    }

    public function test_the_code_is_never_flashed_back_to_the_form(): void
    {
        $this->passPassword();

        $this->from('/login/two-factor')->post('/login/two-factor', ['code' => '654321'])->assertSessionHasErrors('code')->assertSessionMissing('_old_input.code');
    }

    public function test_the_row_of_a_deleted_factor_cannot_be_used_to_finish_a_pending_sign_in(): void
    {
        $this->passPassword();
        UserTwoFactor::whereKey($this->user->id)->delete();

        $this->post('/login/two-factor', ['code' => $this->totp($this->secret)])->assertRedirect('/login');
        $this->assertGuest();
        $this->assertFalse(Auth::check());
    }
}
