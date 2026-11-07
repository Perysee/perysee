<?php

namespace Tests\Feature;

use App\Installation\InstallationState;
use App\Models\AccessLogEntry;
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

class DomainEventAccessLogInteractionTest extends TestCase
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

    public function test_accepting_an_invitation_is_both_announced_to_modules_and_written_to_the_access_log(): void
    {
        $site = Website::factory()->create();
        $owner = User::factory()->create();
        $site->members()->attach($owner, ['role' => 'owner']);
        [, $token] = SiteInvitation::issue($site, 'new@example.com', 'viewer', $owner);

        $this->post('/invitations/'.$token, ['name' => 'N', 'password' => 'a-long-enough-password'])->assertRedirect();

        $this->assertCount(1, $this->received);
        $this->assertSame(1, AccessLogEntry::where('event', 'invitation.accepted')->count());
    }

    public function test_creating_a_share_link_is_both_announced_to_modules_and_written_to_the_access_log(): void
    {
        $site = Website::factory()->create();
        $owner = User::factory()->create();
        $site->members()->attach($owner, ['role' => 'owner']);

        $this->actingAs($owner)->post('/sites/'.$site->id.'/sharing', ['sections' => ['overview']])->assertRedirect();

        $this->assertCount(1, $this->received);
        $this->assertSame(1, AccessLogEntry::where('event', 'share.created')->count());
    }
}
