<?php

namespace Tests\Feature;

use App\Installation\InstallationState;
use App\Models\ShareLink;
use App\Models\SiteInvitation;
use App\Models\User;
use App\Models\Website;
use App\Modules\Contracts\DomainEvent;
use App\Modules\Events\DomainEvents;
use App\Modules\Events\InvitationAccepted;
use App\Modules\Events\ShareLinkCreated;
use App\Modules\Events\ShareLinkRevoked;
use App\Modules\ModuleFailures;
use App\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class DomainEventAdminActionsTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<DomainEvent> */
    private array $received = [];

    protected function setUp(): void
    {
        parent::setUp();
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
        $modules = $this->installationDirectory.'/modules';
        mkdir($modules);
        File::ensureDirectoryExists($modules.'/watcher');
        file_put_contents($modules.'/watcher/module.json', json_encode(['id' => 'watcher', 'name' => 'Watcher', 'version' => '1.0.0', 'core' => '0.1.0']));
        config(['perysee.modules_path' => $modules, 'perysee.version' => '0.4.0']);
        Cache::flush();
        app()->forgetInstance(ModuleRegistry::class);
        app()->forgetInstance(ModuleFailures::class);
        app()->forgetInstance(DomainEvents::class);
        app(ModuleRegistry::class)->enable('watcher');
        foreach ([InvitationAccepted::class, ShareLinkCreated::class, ShareLinkRevoked::class] as $event) {
            app(DomainEvents::class)->listen('watcher', $event, function (DomainEvent $received): void {
                $this->received[] = $received;
            });
        }
    }

    /** @return array{0: Website, 1: User} */
    private function siteWithOwner(): array
    {
        $site = Website::factory()->create();
        $owner = User::factory()->create();
        $site->members()->attach($owner, ['role' => 'owner']);

        return [$site, $owner];
    }

    public function test_accepting_an_invitation_announces_the_new_member_with_ids_only(): void
    {
        [$site, $owner] = $this->siteWithOwner();
        [, $token] = SiteInvitation::issue($site, 'new@example.com', 'viewer', $owner);

        $this->post('/invitations/'.$token, ['name' => 'Secret Name', 'password' => 'a-long-enough-password'])->assertRedirect();

        $user = User::where('email', 'new@example.com')->firstOrFail();
        $this->assertCount(1, $this->received);
        $this->assertSame(['site_id' => $site->id, 'role' => 'viewer', 'new_user' => true, 'member_id' => $user->id], $this->received[0]->payload());
        $this->assertStringNotContainsString('new@example.com', json_encode($this->received[0]->payload()));
        $this->assertStringNotContainsString('Secret Name', json_encode($this->received[0]->payload()));
    }

    public function test_an_existing_user_accepting_is_not_a_new_user_and_a_refused_acceptance_announces_nothing(): void
    {
        [$site, $owner] = $this->siteWithOwner();
        $invited = User::factory()->create(['email' => 'known@example.com']);
        [, $token] = SiteInvitation::issue($site, 'known@example.com', 'owner', $owner);

        $this->post('/invitations/'.$token, ['name' => 'X', 'password' => 'a-long-enough-password'])->assertRedirect('/login');
        $this->assertSame([], $this->received, 'Signing in first: nothing was accepted yet.');

        $this->actingAs($invited)->post('/invitations/'.$token)->assertRedirect();
        $this->assertSame(['site_id' => $site->id, 'role' => 'owner', 'new_user' => false, 'member_id' => $invited->id], $this->received[0]->payload());

        $this->received = [];
        $this->post('/invitations/'.$token)->assertNotFound();
        $this->assertSame([], $this->received);
    }

    public function test_creating_and_revoking_a_share_link_are_announced_without_token_hint_or_label(): void
    {
        [$site, $owner] = $this->siteWithOwner();

        $this->actingAs($owner)->post('/sites/'.$site->id.'/sharing', ['label' => 'Private client label', 'sections' => ['overview', 'pages'], 'password' => 'correct horse', 'expires_on' => now('UTC')->addDays(3)->format('Y-m-d')])->assertRedirect();

        $link = ShareLink::where('website_id', $site->id)->firstOrFail();
        $this->assertCount(1, $this->received);
        $created = $this->received[0]->payload();
        $this->assertSame(['site_id' => $site->id, 'link_id' => $link->id, 'sections' => ['overview', 'pages'], 'protected' => true], array_diff_key($created, ['expires_at' => 1]));
        $this->assertSame($link->expires_at->toIso8601String(), $created['expires_at']);
        $json = json_encode($created);
        $this->assertStringNotContainsString('Private client label', $json);
        $this->assertStringNotContainsString($link->token_hint, $json);
        $this->assertStringNotContainsString($link->token_hash, $json);

        $this->received = [];
        $this->delete('/sites/'.$site->id.'/sharing/'.$link->id)->assertRedirect();
        $this->delete('/sites/'.$site->id.'/sharing/'.$link->id)->assertRedirect();

        $this->assertCount(1, $this->received, 'Revoking a link that is already revoked announces nothing.');
        $this->assertSame(['site_id' => $site->id, 'link_id' => $link->id], $this->received[0]->payload());
    }

    public function test_a_share_link_without_password_or_expiry_is_announced_as_such(): void
    {
        [$site, $owner] = $this->siteWithOwner();

        $this->actingAs($owner)->post('/sites/'.$site->id.'/sharing', ['sections' => ['overview']])->assertRedirect();

        $payload = $this->received[0]->payload();
        $this->assertFalse($payload['protected']);
        $this->assertNull($payload['expires_at']);
    }

    public function test_a_refused_share_action_announces_nothing(): void
    {
        [$site] = $this->siteWithOwner();
        $viewer = User::factory()->create();
        $site->members()->attach($viewer, ['role' => 'viewer']);

        $this->actingAs($viewer)->post('/sites/'.$site->id.'/sharing', ['sections' => ['overview']])->assertForbidden();
        $this->post('/sites/'.$site->id.'/sharing', ['sections' => []])->assertForbidden();

        $this->assertSame([], $this->received);
    }
}
