<?php

namespace Tests\Feature;

use App\Installation\InstallationState;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
    }

    public function test_guest_is_redirected_to_login_and_registration_is_closed(): void
    {
        $this->get('/sites')->assertRedirect('/login');
        $this->get('/register')->assertNotFound();
        $this->post('/register')->assertNotFound();
    }

    public function test_user_can_sign_in_and_sign_out(): void
    {
        $user = User::factory()->create();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/sites');
        $this->assertAuthenticatedAs($user);
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_incorrect_password_is_rejected_and_repeated_attempts_are_limited(): void
    {
        $user = User::factory()->create();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'incorrect'])->assertSessionHasErrors('email');
        }
        $this->post('/login', ['email' => $user->email, 'password' => 'incorrect'])->assertStatus(429);
        $this->assertGuest();
    }

    public function test_changing_password_requires_current_password(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->put('/account/password', ['current_password' => 'wrong', 'password' => 'New-password-123!', 'password_confirmation' => 'New-password-123!'])->assertSessionHasErrors('current_password');
        $this->put('/account/password', ['current_password' => 'password', 'password' => 'New-password-123!', 'password_confirmation' => 'New-password-123!'])->assertSessionHasNoErrors()->assertRedirect('/account/password');
        $this->assertTrue(Hash::check('New-password-123!', $user->fresh()->password));
    }

    public function test_browser_writes_require_csrf_protection(): void
    {
        $this->app->instance('env', 'local');
        $this->post('/login', ['email' => 'owner@example.test', 'password' => 'password'])->assertStatus(419);
        $this->actingAs(User::factory()->create())->post('/sites', ['name' => 'Injected'])->assertStatus(419);
        $this->post('/logout')->assertStatus(419);
    }

    public function test_password_validation_rejects_values_bcrypt_cannot_safely_store(): void
    {
        $this->actingAs(User::factory()->create());
        foreach ([str_repeat('é', 40), "long-password\0with-null"] as $password) {
            $this->put('/account/password', ['current_password' => 'password', 'password' => $password, 'password_confirmation' => $password])->assertSessionHasErrors('password');
        }
    }
}
