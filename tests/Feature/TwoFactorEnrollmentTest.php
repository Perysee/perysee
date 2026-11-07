<?php

namespace Tests\Feature;

use App\Installation\InstallationState;
use App\Models\AccessLogEntry;
use App\Models\User;
use App\Models\UserTwoFactor;
use App\Support\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithTwoFactor;
use Tests\TestCase;

class TwoFactorEnrollmentTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
    }

    public function test_the_security_page_offers_to_start_and_shows_no_secret_before_it_does(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/account/security')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertInertia(fn (Assert $page) => $page->where('screen', 'account.security')->where('twoFactor.enabled', false)->where('twoFactor.setup', null)->where('recoveryCodes', null));
        $this->assertSame(0, UserTwoFactor::count());
    }

    public function test_starting_needs_the_current_password_and_shows_a_key_a_link_and_a_local_qr_code_without_enabling_anything(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/account/security/two-factor', ['current_password' => 'wrong'])->assertSessionHasErrors('current_password');
        $this->assertSame(0, UserTwoFactor::count());

        $this->post('/account/security/two-factor', ['current_password' => 'password'])->assertRedirect('/account/security');
        $secret = UserTwoFactor::findOrFail($user->id)->secret;

        $setupPage = $this->get('/account/security');
        $this->assertTrue($setupPage->viewData('page')['encryptHistory'], 'The TOTP secret is not left readable in history.state.');
        $this->assertTrue($setupPage->viewData('page')['clearHistory']);
        $setupPage->assertInertia(fn (Assert $page) => $page
            ->where('twoFactor.enabled', false)
            ->where('twoFactor.setup.secret', trim(chunk_split($secret, 4, ' ')))
            ->where('twoFactor.setup.uri', fn ($uri) => str_starts_with($uri, 'otpauth://totp/') && str_contains($uri, 'secret='.$secret) && str_contains($uri, 'issuer='))
            ->where('twoFactor.setup.qr', fn ($svg) => str_starts_with($svg, '<svg') && str_contains($svg, '</svg>')));
        $this->assertFalse(app(TwoFactor::class)->enabled($user));
    }

    public function test_the_secret_is_encrypted_in_the_database(): void
    {
        $user = User::factory()->create();
        $secret = app(TwoFactor::class)->begin($user)->secret;

        $stored = DB::table('user_two_factor')->where('user_id', $user->id)->value('secret');

        $this->assertNotSame($secret, $stored);
        $this->assertStringNotContainsString($secret, $stored);
        $this->assertSame($secret, UserTwoFactor::findOrFail($user->id)->secret);
    }

    public function test_a_wrong_code_enables_nothing_and_a_right_one_enables_it_and_shows_eight_recovery_codes_once(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/account/security/two-factor', ['current_password' => 'password']);
        $secret = UserTwoFactor::findOrFail($user->id)->secret;

        $this->post('/account/security/two-factor/confirm', ['code' => '000000'])->assertSessionHasErrors(['code' => 'The code is not valid.']);
        $this->assertFalse(app(TwoFactor::class)->enabled($user));

        $this->post('/account/security/two-factor/confirm', ['code' => $this->totp($secret)])->assertRedirect('/account/security');
        $this->assertTrue(app(TwoFactor::class)->enabled($user));
        $codesPage = $this->get('/account/security');
        $this->assertTrue($codesPage->viewData('page')['encryptHistory'], 'The backup codes are not left readable in history.state.');
        $this->assertTrue($codesPage->viewData('page')['clearHistory']);
        $codesPage->assertInertia(fn (Assert $page) => $page
            ->where('twoFactor.enabled', true)->where('twoFactor.remaining_codes', 8)->where('twoFactor.setup', null)
            ->has('recoveryCodes', 8)->where('recoveryCodes.0', fn ($code) => (bool) preg_match('/^[A-Z2-9]{5}-[A-Z2-9]{5}$/', $code)));
        $this->get('/account/security')->assertInertia(fn (Assert $page) => $page->where('recoveryCodes', null)->where('twoFactor.remaining_codes', 8));
        $this->assertSame(1, AccessLogEntry::where('event', 'twofactor.enabled')->where('user_id', $user->id)->count());
    }

    public function test_the_confirmation_code_cannot_be_reused_to_sign_in_straight_away(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/account/security/two-factor', ['current_password' => 'password']);
        $secret = UserTwoFactor::findOrFail($user->id)->secret;
        $code = $this->totp($secret);
        $this->post('/account/security/two-factor/confirm', ['code' => $code]);

        $this->assertNull(app(TwoFactor::class)->verifyChallenge($user, $code));
    }

    public function test_recovery_codes_are_stored_as_hashes_only(): void
    {
        $user = User::factory()->create();
        [, $codes] = $this->enableTwoFactor($user);

        $hashes = DB::table('user_recovery_codes')->where('user_id', $user->id)->pluck('code_hash')->all();

        $this->assertCount(8, $hashes);
        foreach ($codes as $code) {
            $this->assertNotContains($code, $hashes);
            $this->assertNotContains(str_replace('-', '', $code), $hashes);
        }
        foreach ($hashes as $hash) {
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
        }
    }

    public function test_starting_again_before_confirming_replaces_the_pending_secret_but_never_a_confirmed_one(): void
    {
        $user = User::factory()->create();
        $first = app(TwoFactor::class)->begin($user)->secret;
        $second = app(TwoFactor::class)->begin($user)->secret;
        $this->assertNotSame($first, $second);

        app(TwoFactor::class)->confirm($user, $this->totp($second));
        $this->assertSame($second, app(TwoFactor::class)->begin($user)->secret);
        $this->assertTrue(app(TwoFactor::class)->enabled($user));
    }

    public function test_disabling_needs_the_password_removes_the_factor_and_its_codes_and_is_logged(): void
    {
        $user = User::factory()->create();
        $this->enableTwoFactor($user);

        $this->actingAs($user)->delete('/account/security/two-factor', ['current_password' => 'wrong'])->assertSessionHasErrors('current_password');
        $this->assertTrue(app(TwoFactor::class)->enabled($user));

        $this->delete('/account/security/two-factor', ['current_password' => 'password'])->assertRedirect('/account/security');
        $this->assertFalse(app(TwoFactor::class)->enabled($user));
        $this->assertSame(0, DB::table('user_recovery_codes')->where('user_id', $user->id)->count());
        $this->assertSame(1, AccessLogEntry::where('event', 'twofactor.disabled')->where('user_id', $user->id)->count());
    }

    public function test_regenerating_the_recovery_codes_needs_the_password_and_invalidates_the_old_ones(): void
    {
        $user = User::factory()->create();
        [, $old] = $this->enableTwoFactor($user);

        $this->actingAs($user)->post('/account/security/recovery-codes', ['current_password' => 'wrong'])->assertSessionHasErrors('current_password');
        $this->post('/account/security/recovery-codes', ['current_password' => 'password'])->assertRedirect('/account/security');
        $new = session('recovery_codes');

        $this->assertCount(8, $new);
        $this->assertSame([], array_intersect($old, $new));
        $this->assertNull(app(TwoFactor::class)->verifyChallenge($user, $old[0]));
        $this->assertSame('recovery_code', app(TwoFactor::class)->verifyChallenge($user, $new[0]));
    }

    public function test_regenerating_without_a_second_factor_is_refused(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/account/security/recovery-codes', ['current_password' => 'password'])->assertNotFound();
    }

    public function test_the_security_pages_need_a_signed_in_user_and_the_instance_page_an_administrator(): void
    {
        $this->get('/account/security')->assertRedirect('/login');
        $this->post('/account/security/two-factor', ['current_password' => 'x'])->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/settings/security')->assertForbidden();
    }

    public function test_a_secret_that_can_no_longer_be_decrypted_never_lets_a_code_through(): void
    {
        $user = User::factory()->create();
        [$secret, $codes] = $this->enableTwoFactor($user);
        DB::table('user_two_factor')->where('user_id', $user->id)->update(['secret' => 'not-a-valid-payload']);

        $this->assertNull(app(TwoFactor::class)->verifyChallenge($user, $this->totp($secret)));
        $this->assertSame('recovery_code', app(TwoFactor::class)->verifyChallenge($user, $codes[0]));
    }
}
