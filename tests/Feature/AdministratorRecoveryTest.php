<?php

namespace Tests\Feature;

use App\Installation\InstallationState;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdministratorRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_recovery_resets_existing_admin_and_restores_the_lock(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->artisan('perysee:recover-admin', ['email' => $admin->email])
            ->expectsQuestion('New password (at least 12 characters)', 'Recovered-password-123!')
            ->expectsQuestion('Confirm new password', 'Recovered-password-123!')
            ->assertSuccessful();
        $this->assertTrue(Hash::check('Recovered-password-123!', $admin->fresh()->password));
        $this->assertTrue(app(InstallationState::class)->isLocked());
    }

    public function test_recovery_does_not_promote_a_regular_user(): void
    {
        $user = User::factory()->create();
        $this->artisan('perysee:recover-admin', ['email' => $user->email])->assertFailed();
        $this->assertFalse($user->fresh()->is_admin);
        $this->assertFalse(app(InstallationState::class)->isLocked());
    }
}
