<?php

namespace Tests\Feature;

use App\Installation\InstallationState;
use App\Mail\MailSettings;
use App\Mail\SiteInvitationMail;
use App\Models\SiteInvitation;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
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

    public function test_inviting_without_mail_returns_a_copyable_link_and_a_viewer_cannot_invite(): void
    {
        Mail::fake();
        [$site, $owner] = $this->siteWith('owner');
        $viewer = User::factory()->create();
        $site->members()->attach($viewer, ['role' => 'viewer']);

        $this->actingAs($viewer)->post('/sites/'.$site->id.'/invitations', ['email' => 'a@example.com', 'role' => 'viewer'])->assertForbidden();
        $this->assertDatabaseCount('site_invitations', 0);

        $response = $this->actingAs($owner)->post('/sites/'.$site->id.'/invitations', ['email' => 'A@Example.com', 'role' => 'viewer']);
        $response->assertSessionHasNoErrors()->assertRedirect('/sites/'.$site->id.'/team');
        $link = session('invitation_link');
        $this->assertMatchesRegularExpression('#^http://[^/]+/invitations/[a-f0-9]{64}$#', $link);
        $invitation = SiteInvitation::findPending(basename($link));
        $this->assertSame('a@example.com', $invitation->email);
        $this->assertSame('viewer', $invitation->role);
        $this->assertNull($invitation->last_sent_at);
        Mail::assertNothingSent();

        $this->post('/sites/'.$site->id.'/invitations', ['email' => 'b@example.com', 'role' => 'admin'])->assertSessionHasErrors('role');
        $this->post('/sites/'.$site->id.'/invitations', ['email' => $owner->email, 'role' => 'viewer'])->assertSessionHasErrors('email');
    }

    public function test_the_team_screen_lists_members_and_pending_invitations_for_owners_only(): void
    {
        [$site, $owner] = $this->siteWith('owner');
        $viewer = User::factory()->create(['name' => 'Vera Viewer']);
        $site->members()->attach($viewer, ['role' => 'viewer']);
        SiteInvitation::issue($site, 'pending@example.com', 'viewer', $owner);
        [$settled] = SiteInvitation::issue($site, 'revoked@example.com', 'viewer', $owner);
        $settled->forceFill(['revoked_at' => now()])->save();
        $outsider = User::factory()->create();

        $this->actingAs($outsider)->get('/sites/'.$site->id.'/team')->assertForbidden();
        $this->actingAs($owner)->get('/sites/'.$site->id.'/team')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('screen', 'sites.team')->where('canManage', true)->where('siteLinks.team', '/sites/'.$site->id.'/team')
            ->has('members', 2)->where('members.0.role', 'owner')->where('members.0.you', true)
            ->has('invitations', 1)->where('invitations.0.email', 'pending@example.com')->where('invitations.0.role', 'viewer')
            ->has('invitations.0.resend_url')->has('invitations.0.revoke_url')->missing('invitations.0.token_hash'));

        $this->actingAs($viewer)->get('/sites/'.$site->id.'/team')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('canManage', false)->has('members', 2)->where('invitations', [])->where('invitationLink', null));

        $this->actingAs($owner)->post('/sites/'.$site->id.'/invitations', ['email' => 'next@example.com', 'role' => 'owner']);
        $this->get('/sites/'.$site->id.'/team')->assertInertia(fn (Assert $page) => $page
            ->where('invitationLink', fn ($link) => preg_match('#^http://[^/]+/invitations/[a-f0-9]{64}$#', $link) === 1)
            ->where('invitationEmail', 'next@example.com'));
        $this->get('/sites/'.$site->id.'/team')->assertInertia(fn (Assert $page) => $page->where('invitationLink', null));
    }

    public function test_expired_invitations_are_purged_by_maintenance(): void
    {
        [$site, $owner] = $this->siteWith('owner');
        $make = function (string $email, array $state) use ($site, $owner): int {
            [$invitation] = SiteInvitation::issue($site, $email, 'viewer', $owner);
            $invitation->forceFill($state)->save();

            return $invitation->id;
        };
        $old = now()->subDays(31);
        $recent = now()->subDays(5);
        $gone = [
            $make('expired-long-ago@example.com', ['expires_at' => $old]),
            $make('accepted-long-ago@example.com', ['accepted_at' => $old]),
            $make('revoked-long-ago@example.com', ['revoked_at' => $old]),
        ];
        $kept = [
            $make('pending@example.com', []),
            $make('expired-recently@example.com', ['expires_at' => $recent]),
            $make('accepted-recently@example.com', ['accepted_at' => $recent]),
        ];

        $this->artisan('perysee:maintenance', ['--limit' => 2])->assertSuccessful();
        $this->assertSame(1, SiteInvitation::whereIn('id', $gone)->count(), 'The purge is bounded by the limit.');
        $this->artisan('perysee:maintenance', ['--limit' => 2])->assertSuccessful();

        $this->assertSame(0, SiteInvitation::whereIn('id', $gone)->count());
        $this->assertSame(3, SiteInvitation::whereIn('id', $kept)->count());
        $this->assertTrue($site->members()->whereKey($owner->id)->exists());
    }

    public function test_an_owner_can_change_roles_and_remove_members_of_their_own_site_only(): void
    {
        [$site, $owner] = $this->siteWith('owner');
        $member = User::factory()->create();
        $site->members()->attach($member, ['role' => 'viewer']);
        [$other, $otherOwner] = $this->siteWith('owner');
        $team = '/sites/'.$site->id.'/team/';

        $this->actingAs($member)->put($team.$owner->id, ['role' => 'viewer'])->assertForbidden();
        $this->delete($team.$owner->id)->assertForbidden();

        $this->actingAs($owner)->put($team.$member->id, ['role' => 'owner'])->assertSessionHasNoErrors()->assertRedirect($team);
        $this->assertSame('owner', $site->members()->whereKey($member->id)->first()->pivot->role);
        $this->put($team.$member->id, ['role' => 'superuser'])->assertSessionHasErrors('role');
        $this->put($team.$otherOwner->id, ['role' => 'viewer'])->assertNotFound();
        $this->assertSame('owner', $other->members()->whereKey($otherOwner->id)->first()->pivot->role);

        $this->delete($team.$member->id)->assertRedirect($team);
        $this->assertFalse($site->members()->whereKey($member->id)->exists());
        $this->delete($team.$otherOwner->id)->assertNotFound();
        $this->assertTrue($other->members()->whereKey($otherOwner->id)->exists());
    }

    public function test_a_member_can_leave_a_site_and_a_site_being_deleted_refuses_changes(): void
    {
        [$site, $owner] = $this->siteWith('owner');
        $viewer = User::factory()->create();
        $site->members()->attach($viewer, ['role' => 'viewer']);
        $outsider = User::factory()->create();

        $this->actingAs($outsider)->delete('/sites/'.$site->id.'/team/me')->assertForbidden();
        $this->actingAs($viewer)->delete('/sites/'.$site->id.'/team/me')->assertRedirect('/sites');
        $this->assertFalse($site->members()->whereKey($viewer->id)->exists());

        $site->forceFill(['deleting_at' => now()])->save();
        $this->actingAs($owner)->post('/sites/'.$site->id.'/invitations', ['email' => 'a@example.com', 'role' => 'viewer'])->assertStatus(409);
        $this->put('/sites/'.$site->id.'/team/'.$owner->id, ['role' => 'viewer'])->assertStatus(409);
    }

    public function test_the_last_owner_cannot_leave_be_removed_or_be_demoted(): void
    {
        [$site, $owner] = $this->siteWith('owner');
        $viewer = User::factory()->create();
        $site->members()->attach($viewer, ['role' => 'viewer']);
        $team = '/sites/'.$site->id.'/team/';
        $this->actingAs($owner);

        $this->putJson($team.$owner->id, ['role' => 'viewer'])->assertStatus(422)->assertJsonValidationErrors('member');
        $this->deleteJson($team.$owner->id)->assertStatus(422)->assertJsonValidationErrors('member');
        $this->deleteJson($team.'me')->assertStatus(422)->assertJsonValidationErrors('member');
        $this->assertSame('owner', $site->members()->whereKey($owner->id)->first()->pivot->role);

        $site->members()->updateExistingPivot($viewer->id, ['role' => 'owner']);
        $this->putJson($team.$owner->id, ['role' => 'viewer'])->assertRedirect();
        $this->actingAs($viewer)->deleteJson($team.$viewer->id)->assertStatus(422);
        $this->putJson($team.$viewer->id, ['role' => 'viewer'])->assertStatus(422);
        $this->assertSame(1, $site->members()->wherePivot('role', 'owner')->count());
    }

    public function test_an_owner_can_create_twenty_invitations_an_hour(): void
    {
        [$site, $owner] = $this->siteWith('owner');
        $this->actingAs($owner);

        for ($index = 0; $index < 20; $index++) {
            $this->post('/sites/'.$site->id.'/invitations', ['email' => "person$index@example.com", 'role' => 'viewer'])->assertRedirect();
        }

        $this->post('/sites/'.$site->id.'/invitations', ['email' => 'one-more@example.com', 'role' => 'viewer'])->assertStatus(429);
        $this->assertDatabaseCount('site_invitations', 20);
    }

    public function test_a_configured_mailer_sends_the_invitation_and_a_failure_falls_back_to_the_link(): void
    {
        config(['mail.default' => 'smtp']);
        Mail::fake();
        [$site, $owner] = $this->siteWith('owner');
        $this->actingAs($owner);

        $this->post('/sites/'.$site->id.'/invitations', ['email' => 'a@example.com', 'role' => 'viewer'])->assertRedirect('/sites/'.$site->id.'/team');

        Mail::assertSent(SiteInvitationMail::class, function (SiteInvitationMail $mail) use ($site): bool {
            return $mail->hasTo('a@example.com') && str_contains($mail->url, '/invitations/') && $mail->siteName === $site->name;
        });
        $rendered = (new SiteInvitationMail('http://localhost/invitations/abc', 'My & site', 'owner', 'Ann', 'Nov 14, 2026', 'Perysee'))->render();
        $this->assertStringContainsString('http://localhost/invitations/abc', $rendered);
        $this->assertStringContainsString('My & site', $rendered);
        $this->assertNull(session('invitation_link'), 'The link is not shown when the mail went out.');
        $this->assertNotNull(SiteInvitation::where('email', 'a@example.com')->first()->last_sent_at);

        Mail::shouldReceive('to->send')->andThrow(new \RuntimeException('SMTP is down'));
        $this->post('/sites/'.$site->id.'/invitations', ['email' => 'b@example.com', 'role' => 'viewer'])->assertRedirect('/sites/'.$site->id.'/team');

        $invitation = SiteInvitation::where('email', 'b@example.com')->first();
        $this->assertNull($invitation->last_sent_at);
        $this->assertSame($invitation->id, SiteInvitation::findPending(basename(session('invitation_link')))->id);
        $this->assertNotEmpty(session('error'));
        $this->assertStringNotContainsString('SMTP is down', session('error'));
    }

    public function test_smtp_settings_saved_by_the_administrator_make_invitations_mail_even_though_env_says_log(): void
    {
        config(['mail.default' => 'log']);
        app(MailSettings::class)->save(['enabled' => true, 'host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'starttls', 'from_address' => 'noreply@example.com']);
        Mail::fake();
        [$site, $owner] = $this->siteWith('owner');
        $this->actingAs($owner);

        $this->post('/sites/'.$site->id.'/invitations', ['email' => 'a@example.com', 'role' => 'viewer'])->assertRedirect('/sites/'.$site->id.'/team');

        Mail::assertSent(SiteInvitationMail::class, fn (SiteInvitationMail $mail): bool => $mail->hasTo('a@example.com'));
        $this->assertNull(session('invitation_link'));
        $this->assertNotNull(SiteInvitation::where('email', 'a@example.com')->first()->last_sent_at);
    }

    public function test_resend_rotates_the_token_and_invalidates_the_old_link(): void
    {
        [$site, $owner] = $this->siteWith('owner');
        [$invitation, $oldToken] = SiteInvitation::issue($site, 'a@example.com', 'viewer', $owner);
        $invitation->forceFill(['expires_at' => now()->addDay()])->save();
        $base = '/sites/'.$site->id.'/invitations/'.$invitation->id;

        $this->actingAs($owner)->post($base.'/resend')->assertRedirect('/sites/'.$site->id.'/team');
        $newToken = basename(session('invitation_link'));

        $this->assertNotSame($oldToken, $newToken);
        $this->assertNull(SiteInvitation::findPending($oldToken));
        $this->assertSame($invitation->id, SiteInvitation::findPending($newToken)->id);
        $this->assertTrue($invitation->fresh()->expires_at->isAfter(now()->addDays(6)), 'Resending restarts the validity.');
        $this->post($base.'/resend')->assertStatus(429);

        $this->delete($base)->assertRedirect('/sites/'.$site->id.'/team');
        $this->assertNull(SiteInvitation::findPending($newToken));
        $this->assertNotNull($invitation->fresh()->revoked_at);
        $this->post($base.'/resend')->assertNotFound();
    }

    public function test_only_an_owner_of_the_same_site_can_resend_or_revoke_an_invitation(): void
    {
        [$site, $owner] = $this->siteWith('owner');
        [$other, $otherOwner] = $this->siteWith('owner');
        $viewer = User::factory()->create();
        $site->members()->attach($viewer, ['role' => 'viewer']);
        [$invitation, $token] = SiteInvitation::issue($site, 'a@example.com', 'viewer', $owner);
        $path = '/sites/%d/invitations/'.$invitation->id;

        $this->actingAs($viewer)->delete(sprintf($path, $site->id))->assertForbidden();
        $this->post(sprintf($path, $site->id).'/resend')->assertForbidden();
        $this->actingAs($otherOwner)->delete(sprintf($path, $site->id))->assertForbidden();
        $this->delete(sprintf($path, $other->id))->assertNotFound();
        $this->assertSame($invitation->id, SiteInvitation::findPending($token)->id);
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
