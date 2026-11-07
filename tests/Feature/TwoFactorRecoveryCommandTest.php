<?php

namespace Tests\Feature;

use App\Installation\InstallationState;
use App\Models\AccessLogEntry;
use App\Models\User;
use App\Models\UserSession;
use App\Support\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use Tests\Support\InteractsWithTwoFactor;
use Tests\TestCase;

class TwoFactorRecoveryCommandTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    private function recover(User $admin, bool $reset): PendingCommand
    {
        return $this->artisan('perysee:recover-admin', ['email' => $admin->email] + ($reset ? ['--reset-2fa' => true] : []))
            ->expectsQuestion('New password (at least 12 characters)', 'Recovered-password-123!')
            ->expectsQuestion('Confirm new password', 'Recovered-password-123!');
    }

    public function test_without_the_option_the_second_factor_is_kept(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->enableTwoFactor($admin);

        $this->recover($admin, false)->assertSuccessful();

        $this->assertTrue(app(TwoFactor::class)->enabled($admin));
        $this->post('/login', ['email' => $admin->email, 'password' => 'Recovered-password-123!'])->assertRedirect('/login/two-factor');
    }

    public function test_with_the_option_the_factor_and_codes_are_removed_and_the_action_is_logged_as_the_command_line(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->enableTwoFactor($admin);

        $this->recover($admin, true)->expectsOutputToContain('Two-factor authentication removed')->assertSuccessful();

        $this->assertFalse(app(TwoFactor::class)->enabled($admin));
        $this->assertSame(0, DB::table('user_recovery_codes')->where('user_id', $admin->id)->count());
        $entry = AccessLogEntry::where('event', 'twofactor.disabled')->firstOrFail();
        $this->assertSame($admin->id, $entry->user_id);
        $this->assertSame('cli', $entry->context['actor']);
        $this->assertNull($entry->ip);
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
        $this->post('/login', ['email' => $admin->email, 'password' => 'Recovered-password-123!'])->assertRedirect('/sites');
    }

    public function test_the_open_sessions_of_the_administrator_are_emptied_by_a_recovery(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        UserSession::create(['user_id' => $admin->id, 'session_hash' => str_repeat('a', 64), 'session_id_enc' => 'x', 'device' => 'Test', 'created_at' => now(), 'last_seen_at' => now()]);

        $this->recover($admin, false)->assertSuccessful();

        $this->assertSame(0, UserSession::count());
    }

    public function test_a_regular_user_is_still_refused_with_the_option(): void
    {
        $user = User::factory()->create();
        $this->enableTwoFactor($user);

        $this->artisan('perysee:recover-admin', ['email' => $user->email, '--reset-2fa' => true])->assertFailed();

        $this->assertTrue(app(TwoFactor::class)->enabled($user));
    }
}
