<?php

namespace Tests\Feature;

use App\Installation\InstallationState;
use App\Models\SiteInvitation;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
    }

    private function siteWith(string $role): array
    {
        $site = Website::factory()->create();
        $user = User::factory()->create();
        $site->members()->attach($user, ['role' => $role]);

        return [$site, $user];
    }

    public function test_an_owner_can_create_an_invitation_that_stores_only_a_token_hash(): void
    {
        [$site, $owner] = $this->siteWith('owner');

        [$invitation, $token] = SiteInvitation::issue($site, 'New.Person@Example.com', 'viewer', $owner);

        $this->assertSame(64, strlen($token));
        $this->assertSame('new.person@example.com', $invitation->email);
        $this->assertSame(hash('sha256', $token), $invitation->token_hash);
        $this->assertDatabaseMissing('site_invitations', ['token_hash' => $token]);
        $this->assertTrue($invitation->expires_at->between(now()->addDays(7)->subMinute(), now()->addDays(7)->addMinute()));
        $this->assertTrue($invitation->isPending());
        $this->assertSame($invitation->id, SiteInvitation::findPending($token)?->id);
        $this->assertNull(SiteInvitation::findPending(str_repeat('0', 64)));
    }
}
