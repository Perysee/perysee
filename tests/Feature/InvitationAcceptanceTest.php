<?php

namespace Tests\Feature;

use App\Installation\InstallationState;
use App\Models\SiteInvitation;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InvitationAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
    }

    /** @return array{0: Website, 1: SiteInvitation, 2: string} */
    private function invitation(string $email = 'new@example.com', string $role = 'viewer'): array
    {
        $site = Website::factory()->create(['name' => 'Shared blog']);
        $owner = User::factory()->create();
        $site->members()->attach($owner, ['role' => 'owner']);
        [$invitation, $token] = SiteInvitation::issue($site, $email, $role, $owner);

        return [$site, $invitation, $token];
    }

    private function details(): array
    {
        return ['name' => 'New Person', 'password' => 'a-long-enough-password'];
    }

    public function test_a_new_user_accepts_an_invitation_and_becomes_a_member_with_the_invited_role(): void
    {
        [$site, $invitation, $token] = $this->invitation(role: 'owner');

        $this->get('/invitations/'.$token)->assertOk()
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertInertia(fn (Assert $page) => $page->where('screen', 'invitation')->where('invitation.site', 'Shared blog')->where('invitation.role', 'owner')->where('invitation.email', 'new@example.com'));
        $this->assertTrue($invitation->fresh()->isPending(), 'Opening the page must not consume the token.');
        $this->assertGuest();

        $this->post('/invitations/'.$token, $this->details())->assertRedirect('/sites/'.$site->id);

        $user = User::where('email', 'new@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertFalse((bool) $user->is_admin);
        $this->assertNull($user->email_verified_at, 'No mail was sent, so the address is not verified.');
        $this->assertTrue(Hash::check('a-long-enough-password', $user->password));
        $this->assertSame('owner', $site->members()->whereKey($user->id)->first()->pivot->role);
        $this->assertNotNull($invitation->fresh()->accepted_at);
    }

    public function test_an_invitation_is_single_use_and_expired_or_revoked_ones_look_like_unknown_ones(): void
    {
        [$site, $invitation, $token] = $this->invitation();
        $this->post('/invitations/'.$token, $this->details())->assertRedirect();
        $this->post('/logout');
        $this->get('/invitations/'.$token)->assertNotFound();
        $this->post('/invitations/'.$token, ['name' => 'Again', 'password' => 'another-long-password'])->assertNotFound();
        $this->assertSame(1, User::where('email', 'new@example.com')->count());

        [, $expired, $expiredToken] = $this->invitation('late@example.com');
        $expired->forceFill(['expires_at' => now()->subSecond()])->save();
        [, $revoked, $revokedToken] = $this->invitation('gone@example.com');
        $revoked->forceFill(['revoked_at' => now()])->save();

        foreach ([$expiredToken, $revokedToken, str_repeat('a', 64), 'short'] as $candidate) {
            $this->get('/invitations/'.$candidate)->assertNotFound();
            $this->post('/invitations/'.$candidate, $this->details())->assertNotFound();
        }
        $this->assertGuest();
    }

    public function test_an_invitation_cannot_be_accepted_by_another_account(): void
    {
        [$site, $invitation, $token] = $this->invitation('invited@example.com');
        $stranger = User::factory()->create(['email' => 'stranger@example.com']);

        $response = $this->actingAs($stranger)->post('/invitations/'.$token);

        $response->assertSessionHasErrors(['invitation' => 'This invitation is not valid for this account.']);
        $this->assertStringNotContainsString('invited@example.com', json_encode(session('errors')->getBag('default')->all()));
        $this->assertFalse($site->members()->whereKey($stranger->id)->exists());
        $this->assertTrue($invitation->fresh()->isPending());
    }

    public function test_a_signed_in_invitee_joins_without_losing_a_higher_role_and_a_guest_with_an_account_is_sent_to_sign_in(): void
    {
        [$site, $invitation, $token] = $this->invitation('member@example.com', 'viewer');
        $invitee = User::factory()->create(['email' => 'Member@Example.com']);

        $this->post('/invitations/'.$token, $this->details())->assertRedirect('/login');
        $this->assertSame('/invitations/'.$token, parse_url(session('url.intended'), PHP_URL_PATH));
        $this->assertFalse($site->members()->whereKey($invitee->id)->exists());
        $this->assertTrue($invitation->fresh()->isPending());

        $this->actingAs($invitee)->post('/invitations/'.$token)->assertRedirect('/sites/'.$site->id);
        $this->assertSame('viewer', $site->members()->whereKey($invitee->id)->first()->pivot->role);
        $this->assertNotNull($invitation->fresh()->accepted_at);

        [$other, , $otherToken] = $this->invitation('member@example.com', 'viewer');
        $other->members()->attach($invitee, ['role' => 'owner']);
        $this->post('/invitations/'.$otherToken)->assertRedirect();
        $this->assertSame('owner', $other->members()->whereKey($invitee->id)->first()->pivot->role);
    }

    public function test_a_weak_password_is_refused_and_keeps_the_invitation_usable(): void
    {
        [, $invitation, $token] = $this->invitation();

        $this->post('/invitations/'.$token, ['name' => 'New Person', 'password' => 'short'])->assertSessionHasErrors('password');

        $this->assertTrue($invitation->fresh()->isPending());
        $this->assertDatabaseMissing('users', ['email' => 'new@example.com']);
    }
}
