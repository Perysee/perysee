<?php

namespace Tests\Feature;

use App\Analytics\Continuity\ContinuityLog;
use App\Installation\InstallationState;
use App\Models\User;
use App\Models\Website;
use App\Modules\Events\ContinuityBreakRecorded;
use App\Modules\Events\DomainEvents;
use App\Modules\ModuleApi;
use App\Modules\ModuleFailures;
use App\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** The domain event of a break of the continuity log: published after the commit, for `breaks` only, never by the collector. */
class ContinuityBreakEventTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array<string, mixed>> */
    private array $received = [];

    /** Whether the row was already in the log when the module was told. */
    private array $loggedWhenTold = [];

    private User $owner;

    private Website $site;

    protected function setUp(): void
    {
        parent::setUp();
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
        $modules = $this->installationDirectory.'/modules';
        mkdir($modules);
        File::ensureDirectoryExists($modules.'/watcher');
        file_put_contents($modules.'/watcher/module.json', json_encode(['id' => 'watcher', 'name' => 'Watcher', 'version' => '1.0.0', 'core' => '0.5.0']));
        config(['perysee.modules_path' => $modules, 'perysee.version' => '0.5.0']);
        Cache::flush();
        app()->forgetInstance(ModuleRegistry::class);
        app()->forgetInstance(ModuleFailures::class);
        app()->forgetInstance(DomainEvents::class);
        app(ModuleRegistry::class)->enable('watcher');
        $this->owner = User::factory()->create(['is_admin' => true]);
        $this->site = Website::factory()->create(['allowed_domains' => ['example.com'], 'domain' => 'example.com', 'tracking_mode' => 'pageviews', 'consent_required' => true, 'timezone' => 'UTC']);
        $this->site->members()->attach($this->owner, ['role' => 'owner']);
    }

    private function listen(): void
    {
        app(DomainEvents::class)->listen('watcher', ContinuityBreakRecorded::class, function (ContinuityBreakRecorded $event): void {
            $this->received[] = $event->payload();
            $this->loggedWhenTold[] = DB::table('continuity_log')->where('kind', $event->kind)->exists();
        });
    }

    private function save(array $changes = []): void
    {
        $this->actingAs($this->owner)->put('/sites/'.$this->site->id, array_merge([
            'name' => $this->site->name, 'domain' => 'example.com', 'allowed_domains' => ['example.com'], 'timezone' => 'UTC',
            'tracking_mode' => 'pageviews', 'consent_required' => '1',
        ], $changes))->assertSessionHasNoErrors();
    }

    public function test_the_event_belongs_to_the_catalog_and_to_release_0_5_0(): void
    {
        $this->assertContains(ContinuityBreakRecorded::class, DomainEvents::CATALOG);
        $this->assertSame('0.5.0', ContinuityBreakRecorded::SINCE);
        $this->assertSame(1, ModuleApi::VERSION);
    }

    public function test_changing_consent_tells_the_module_after_the_response_with_no_values(): void
    {
        $this->listen();

        $this->save(['consent_required' => '0']);

        $this->assertSame([['site_id' => $this->site->id, 'kind' => 'consent', 'scope' => 'all', 'data_from' => now('UTC')->toDateString()]], $this->received);
        $this->assertSame([true], $this->loggedWhenTold, 'the row is committed before the module hears of it');
        $this->assertStringNotContainsString('example.com', json_encode($this->received));
    }

    public function test_a_note_a_rename_or_a_second_identical_save_tell_nothing(): void
    {
        $this->listen();

        $this->save(['name' => 'Another name']);
        $this->save(['allowed_domains' => ['example.com', 'www.example.com']]);
        $this->save(['allowed_domains' => ['example.com', 'www.example.com'], 'consent_required' => '0']);
        $this->save(['allowed_domains' => ['example.com', 'www.example.com'], 'consent_required' => '0']);

        $this->assertSame(['consent'], array_column($this->received, 'kind'));
    }

    public function test_every_break_of_one_save_is_told_once_each(): void
    {
        $this->listen();

        $this->save(['consent_required' => '0', 'tracking_mode' => 'sessions']);

        $this->assertEqualsCanonicalizing(['consent', 'tracking_mode'], array_column($this->received, 'kind'));
    }

    public function test_a_shorter_retention_tells_the_module_for_the_instance_and_a_longer_one_does_not(): void
    {
        $this->listen();

        $this->actingAs($this->owner)->put('/health', ['raw_retention_days' => 90, 'stats_retention_days' => 2000])->assertSessionHasNoErrors();
        $this->assertSame([], $this->received);
        $this->actingAs($this->owner)->put('/health', ['raw_retention_days' => 30, 'stats_retention_days' => 2000])->assertSessionHasNoErrors();

        $this->assertSame([['site_id' => null, 'kind' => 'retention_change', 'scope' => 'all', 'data_from' => now('UTC')->toDateString()]], $this->received);
    }

    public function test_a_purge_ahead_of_the_retention_tells_the_module(): void
    {
        $this->listen();
        DB::table('events')->insert(['website_id' => $this->site->id, 'received_at' => now('UTC')->subDays(40), 'processed_at' => now('UTC')->subDays(40), 'event_type' => 1, 'path' => '/', 'consent_given' => true]);

        $this->artisan('perysee:purge', ['target' => 'events', '--older-than' => '30d', '--force' => true, '--yes' => true])->assertSuccessful();

        $this->assertSame(['retention_purge'], array_column($this->received, 'kind'));
        $this->assertNull($this->received[0]['site_id']);
    }

    public function test_the_log_builds_no_event_for_nobody_and_none_for_a_note(): void
    {
        $log = app(ContinuityLog::class);
        $this->assertNull($log->record('consent', $this->site->id, 'breaks', 'all', []), 'nobody listens: nothing is built');

        $this->listen();
        $this->assertNull($log->record('timezone', $this->site->id, 'note', 'all', []));
        $event = $log->record('consent', $this->site->id, 'breaks', 'all', [], '2026-10-01');
        $this->assertInstanceOf(ContinuityBreakRecorded::class, $event);
        $this->assertSame($this->site->id, $event->siteId());
        $this->assertSame([], $this->received, 'recording alone publishes nothing: the caller does, after its commit');
    }

    public function test_a_failing_listener_never_changes_the_outcome_of_the_save(): void
    {
        app(DomainEvents::class)->listen('watcher', ContinuityBreakRecorded::class, function (): void {
            throw new \RuntimeException('boom');
        });

        $this->save(['consent_required' => '0']);

        $this->assertFalse((bool) $this->site->fresh()->consent_required);
        $this->assertSame(1, DB::table('continuity_log')->count());
    }
}
