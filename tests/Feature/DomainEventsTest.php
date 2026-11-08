<?php

namespace Tests\Feature;

use App\Installation\InstallationState;
use App\Models\Website;
use App\Modules\Contracts\DomainEvent;
use App\Modules\Events\AggregationCompleted;
use App\Modules\Events\DomainEvents;
use App\Modules\Events\GoalConverted;
use App\Modules\Events\ModuleQuarantined;
use App\Modules\Events\StorageBudgetExceeded;
use App\Modules\ModuleApi;
use App\Modules\ModuleFailures;
use App\Modules\ModuleLoader;
use App\Modules\ModuleRegistry;
use App\Modules\ModuleScope;
use App\Modules\Notifications\Notification;
use App\Modules\Notifications\NotificationChannels;
use App\Modules\Reports\ReportSources;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class DomainEventsTest extends TestCase
{
    use RefreshDatabase;

    private string $modulesPath;

    protected function setUp(): void
    {
        parent::setUp();
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
        $this->modulesPath = $this->installationDirectory.'/modules';
        mkdir($this->modulesPath);
        config([
            'perysee.modules_path' => $this->modulesPath,
            'perysee.version' => '0.4.0',
            'perysee.module_failures' => ['window' => 600, 'threshold' => ['stable' => 4, 'beta' => 3, 'experimental' => 2]],
        ]);
        Cache::flush();
        app()->forgetInstance(ModuleRegistry::class);
        app()->forgetInstance(ModuleFailures::class);
        app()->forgetInstance(ModuleLoader::class);
        app()->forgetInstance(ModuleScope::class);
        app()->forgetInstance(DomainEvents::class);
    }

    private function install(string $id, bool $enabled = true, array $manifest = []): void
    {
        File::ensureDirectoryExists($this->modulesPath.'/'.$id);
        file_put_contents($this->modulesPath.'/'.$id.'/module.json', json_encode($manifest + ['id' => $id, 'name' => ucfirst($id), 'version' => '1.0.0', 'core' => '0.1.0']));
        if ($enabled) {
            app(ModuleRegistry::class)->enable($id, acceptRisk: true);
        }
    }

    private function events(): DomainEvents
    {
        return app(DomainEvents::class);
    }

    private function completed(): AggregationCompleted
    {
        return new AggregationCompleted(3, 12, '2026-11-07T10:00:00+00:00');
    }

    private function isEnabled(string $id): bool
    {
        app(ModuleRegistry::class)->refresh();

        return app(ModuleRegistry::class)->all()[$id]->enabled;
    }

    public function test_an_enabled_module_receives_the_event_it_listens_to(): void
    {
        $this->install('alerts');
        $received = [];
        $this->events()->listen('alerts', AggregationCompleted::class, function (AggregationCompleted $event) use (&$received): void {
            $received[] = $event->payload();
        });

        $this->events()->publish($this->completed());

        $this->assertSame([['processed' => 3, 'duration_ms' => 12, 'at' => '2026-11-07T10:00:00+00:00']], $received);
    }

    public function test_a_listener_is_called_only_for_its_own_event(): void
    {
        $this->install('alerts');
        $calls = 0;
        $this->events()->listen('alerts', GoalConverted::class, function () use (&$calls): void {
            $calls++;
        });

        $this->events()->publish($this->completed());

        $this->assertSame(0, $calls);
    }

    public function test_a_class_name_is_built_when_the_event_arrives(): void
    {
        $this->install('alerts');
        $listener = new class
        {
            public static int $calls = 0;

            public function __invoke(DomainEvent $event): void
            {
                self::$calls++;
            }
        };
        $listener::$calls = 0;

        $this->events()->listen('alerts', AggregationCompleted::class, $listener::class);
        $this->events()->publish($this->completed());

        $this->assertSame(1, $listener::$calls);
    }

    public function test_a_disabled_or_unknown_module_receives_nothing(): void
    {
        $this->install('off', enabled: false);
        $calls = 0;
        foreach (['off', 'ghost'] as $module) {
            $this->events()->listen($module, AggregationCompleted::class, function () use (&$calls): void {
                $calls++;
            });
        }

        $this->events()->publish($this->completed());

        $this->assertSame(0, $calls);
    }

    public function test_an_event_about_a_site_reaches_only_the_modules_active_on_that_site(): void
    {
        $this->install('everywhere');
        $this->install('here');
        $this->install('elsewhere');
        $site = Website::factory()->create();
        $other = Website::factory()->create();
        app(ModuleScope::class)->limitTo('here', [$site->id]);
        app(ModuleScope::class)->limitTo('elsewhere', [$other->id]);
        $got = [];
        foreach (['everywhere', 'here', 'elsewhere'] as $module) {
            $this->events()->listen($module, GoalConverted::class, function () use (&$got, $module): void {
                $got[] = $module;
            });
        }

        $this->events()->publish(new GoalConverted($site->id, 1, 1, 2, '2026-11-07T10:00:00+00:00', '2026-11-07T10:05:00+00:00'));

        $this->assertSame(['everywhere', 'here'], $got);
    }

    public function test_an_event_about_the_instance_ignores_site_limits(): void
    {
        $this->install('limited');
        app(ModuleScope::class)->limitTo('limited', []);
        $calls = 0;
        $this->events()->listen('limited', AggregationCompleted::class, function () use (&$calls): void {
            $calls++;
        });

        $this->events()->publish($this->completed());

        $this->assertSame(1, $calls);
    }

    public function test_publishing_with_no_listener_does_not_query_the_database(): void
    {
        $this->install('alerts');
        $this->assertFalse($this->events()->listening(AggregationCompleted::class));

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->events()->publish($this->completed());
        $this->events()->publishAfterResponse($this->completed());
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame([], $queries);
    }

    public function test_publish_after_response_waits_for_the_application_to_terminate_and_fires_once(): void
    {
        $this->install('alerts');
        $calls = 0;
        $this->events()->listen('alerts', AggregationCompleted::class, function () use (&$calls): void {
            $calls++;
        });

        $this->events()->publishAfterResponse($this->completed());
        $this->assertSame(0, $calls);

        app()->terminate();
        app()->terminate();
        $this->assertSame(1, $calls);
    }

    public function test_a_failing_listener_blocks_neither_the_publisher_nor_the_other_modules(): void
    {
        $this->install('flaky');
        $this->install('steady');
        $steady = 0;
        $this->events()->listen('flaky', AggregationCompleted::class, function (): void {
            throw new RuntimeException('boom');
        });
        $this->events()->listen('steady', AggregationCompleted::class, function () use (&$steady): void {
            $steady++;
        });

        $this->events()->publish($this->completed());

        $this->assertSame(1, $steady);
        $this->assertSame(1, app(ModuleFailures::class)->total('flaky'));
        $this->assertSame(0, app(ModuleFailures::class)->total('steady'));
        $this->assertTrue($this->isEnabled('flaky'));
    }

    public function test_a_php_error_in_a_listener_is_isolated_too(): void
    {
        $this->install('typo');
        $this->events()->listen('typo', AggregationCompleted::class, function (): void {
            (fn (int $number) => $number)('not a number');
        });

        $this->events()->publish($this->completed());

        $this->assertSame(1, app(ModuleFailures::class)->total('typo'));
    }

    public function test_repeated_failures_quarantine_the_module_and_it_is_not_called_again(): void
    {
        $this->install('flaky');
        $calls = 0;
        $this->events()->listen('flaky', AggregationCompleted::class, function () use (&$calls): void {
            $calls++;
            throw new RuntimeException('boom');
        });

        for ($i = 0; $i < 5; $i++) {
            $this->events()->publish($this->completed());
        }

        $this->assertSame(3, $calls, 'A beta module is put in quarantine at its third failure and receives nothing after.');
        $this->assertFalse($this->isEnabled('flaky'));
        $this->assertNotNull(app(ModuleFailures::class)->quarantine('flaky'));
    }

    public function test_a_module_put_in_quarantine_by_an_earlier_listener_is_skipped_in_the_same_delivery(): void
    {
        $this->install('victim');
        $this->install('killer');
        $victimCalls = 0;
        $this->events()->listen('killer', AggregationCompleted::class, function (): void {
            app(ModuleRegistry::class)->disable('victim');
        });
        $this->events()->listen('victim', AggregationCompleted::class, function () use (&$victimCalls): void {
            $victimCalls++;
        });

        $this->events()->publish($this->completed());

        $this->assertSame(0, $victimCalls);
    }

    public function test_the_time_budget_skips_the_remaining_listeners_and_counts_the_slow_one(): void
    {
        config(['perysee.module_events.budget_ms' => 20]);
        $this->install('slow');
        $this->install('late');
        $late = 0;
        $this->events()->listen('slow', AggregationCompleted::class, function (): void {
            usleep(60_000);
        });
        $this->events()->listen('late', AggregationCompleted::class, function () use (&$late): void {
            $late++;
        });

        $this->events()->publish($this->completed());

        $this->assertSame(0, $late);
        $this->assertSame(1, app(ModuleFailures::class)->total('slow'));
    }

    public function test_an_event_published_while_another_is_delivered_waits_for_it(): void
    {
        $this->install('first');
        $this->install('second');
        $order = [];
        $this->events()->listen('first', AggregationCompleted::class, function () use (&$order): void {
            $order[] = 'first:start';
            $this->events()->publish(new GoalConverted(1, 1, 1, 1, '2026-11-07T10:00:00+00:00', '2026-11-07T10:05:00+00:00'));
            $order[] = 'first:end';
        });
        $this->events()->listen('second', GoalConverted::class, function () use (&$order): void {
            $order[] = 'second';
        });

        $this->events()->publish($this->completed());

        $this->assertSame(['first:start', 'first:end', 'second'], $order);
    }

    public function test_a_listener_that_republishes_its_own_event_cannot_loop_forever(): void
    {
        $this->install('loop');
        $calls = 0;
        $this->events()->listen('loop', AggregationCompleted::class, function () use (&$calls): void {
            $calls++;
            $this->events()->publish($this->completed());
        });

        $this->events()->publish($this->completed());

        $this->assertLessThanOrEqual(60, $calls);
        $this->assertGreaterThan(1, $calls);
    }

    public function test_only_catalog_events_can_be_listened_to_and_listeners_must_be_callable(): void
    {
        try {
            $this->events()->listen('x', \stdClass::class, fn () => null);
            $this->fail('A class that is not a domain event must be refused.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('not a Perysee domain event', $exception->getMessage());
        }
        $this->expectException(InvalidArgumentException::class);
        $this->events()->listen('x', AggregationCompleted::class, 'NoSuchClass');
    }

    public function test_a_quarantine_is_announced_to_the_other_modules_only_and_without_the_reason(): void
    {
        $this->install('base', manifest: ['stability' => 'stable']);
        $this->install('addon', manifest: ['requires' => ['base']]);
        $this->install('watcher');
        $this->install('flaky');
        $seen = [];
        foreach (['base', 'addon', 'watcher', 'flaky'] as $module) {
            $this->events()->listen($module, ModuleQuarantined::class, function (ModuleQuarantined $event) use (&$seen, $module): void {
                $seen[$module] = $event->payload();
            });
        }

        for ($i = 0; $i < 4; $i++) {
            app(ModuleFailures::class)->report('base', new RuntimeException('secret-detail'), 'test');
        }

        $this->assertSame(['watcher', 'flaky'], array_keys($seen));
        $payload = $seen['watcher'];
        $this->assertSame(['module_id', 'stability', 'dependents', 'at'], array_keys($payload));
        $this->assertSame('base', $payload['module_id']);
        $this->assertSame('stable', $payload['stability']);
        $this->assertSame(['addon'], $payload['dependents']);
        $this->assertStringNotContainsString('secret-detail', json_encode($payload));
    }

    public function test_a_listener_that_fails_during_a_quarantine_announcement_does_not_loop(): void
    {
        $this->install('victim');
        $this->install('bad');
        $this->install('good');
        $heard = [];
        $this->events()->listen('bad', ModuleQuarantined::class, function (): void {
            throw new RuntimeException('boom');
        });
        $this->events()->listen('good', ModuleQuarantined::class, function (ModuleQuarantined $event) use (&$heard): void {
            $heard[] = $event->moduleId;
        });
        config(['perysee.module_failures.threshold.beta' => 2]);
        // One failure each already: the next one quarantines.
        app(ModuleFailures::class)->report('bad', new RuntimeException('x'), 'test');
        app(ModuleFailures::class)->report('victim', new RuntimeException('x'), 'test');
        app(ModuleFailures::class)->report('victim', new RuntimeException('x'), 'test');

        $this->assertFalse($this->isEnabled('victim'));
        $this->assertFalse($this->isEnabled('bad'), 'The module that failed was put in quarantine in its turn.');
        $this->assertSame(['victim', 'bad'], $heard, 'The second announcement waited for the first one and reached the module still enabled.');
    }

    public function test_a_module_provider_subscribes_with_listen_and_receives_events(): void
    {
        $this->makeProviderModule('provider-ok', '0.4.0', "\$this->listen(\\App\\Modules\\Events\\AggregationCompleted::class, function (\$event) { app()->instance('provider-ok.got', \$event->processed); });");

        app(ModuleRegistry::class)->enable('provider-ok');
        app(ModuleLoader::class)->load();
        $this->assertSame([], app(ModuleLoader::class)->failures());
        $this->events()->publish($this->completed());

        $this->assertSame(3, app('provider-ok.got'));
    }

    public function test_a_module_provider_declares_a_channel_and_a_report_source(): void
    {
        $this->makeProviderModule('provider-reg', '0.4.0', "\$this->notificationChannel('provider-reg.log', \\Perysee\\Modules\\ProviderReg\\LogChannel::class); \$this->reportSource('provider-reg.figures', \\Perysee\\Modules\\ProviderReg\\Figures::class);");
        file_put_contents($this->modulesPath.'/provider-reg/src/LogChannel.php', "<?php\nnamespace Perysee\\Modules\\ProviderReg;\nuse App\\Modules\\Contracts\\NotificationChannel;\nuse App\\Modules\\Notifications\\Notification;\nfinal class LogChannel implements NotificationChannel {\n public function label(): string { return 'Log'; }\n public function configured(): bool { return true; }\n public function send(Notification \$n, string \$target): void { app()->instance('provider-reg.sent', \$n->subject.'>'.\$target); }\n}\n");
        file_put_contents($this->modulesPath.'/provider-reg/src/Figures.php', "<?php\nnamespace Perysee\\Modules\\ProviderReg;\nuse App\\Modules\\Contracts\\ReportSource;\nuse App\\Modules\\Reports\\ReportSection;\nuse Illuminate\\Support\\Carbon;\nfinal class Figures implements ReportSource {\n public function label(): string { return 'Figures'; }\n public function sections(int \$siteId, Carbon \$from, Carbon \$to): array { return [new ReportSection('Figures', [['label' => 'Site', 'value' => \$siteId]])]; }\n}\n");

        app(ModuleRegistry::class)->enable('provider-reg');
        app(ModuleLoader::class)->load();
        $this->assertSame([], app(ModuleLoader::class)->failures());
        $site = Website::factory()->create();

        $this->assertTrue(app(NotificationChannels::class)->send('provider-reg.log', 'ops', new Notification('Hi', 'Body')));
        $this->assertSame('Hi>ops', app('provider-reg.sent'));
        $report = collect(app(ReportSources::class)->collect($site->id, now('UTC')->subDay(), now('UTC')))->firstWhere('key', 'provider-reg.figures');
        $this->assertSame([['label' => 'Site', 'value' => (string) $site->id]], $report['sections'][0]->rows);
    }

    public function test_a_module_that_uses_events_without_requiring_a_core_that_has_them_fails_with_a_clear_reason(): void
    {
        $this->makeProviderModule('provider-old', '0.3.0', '$this->listen(\\App\\Modules\\Events\\AggregationCompleted::class, fn () => null);');

        app(ModuleRegistry::class)->enable('provider-old');
        app(ModuleLoader::class)->load();

        $reason = app(ModuleLoader::class)->failures()['provider-old'];
        $this->assertStringContainsString('needs Perysee '.ModuleApi::DOMAIN_EVENTS_SINCE, $reason);
        $this->assertFalse($this->events()->listening(AggregationCompleted::class));
    }

    public function test_an_event_added_after_the_bus_needs_its_own_release_in_the_manifest(): void
    {
        $this->makeProviderModule('provider-budget', '0.4.0', '$this->listen(\\App\\Modules\\Events\\StorageBudgetExceeded::class, fn () => null);');

        app(ModuleRegistry::class)->enable('provider-budget');
        app(ModuleLoader::class)->load();

        $reason = app(ModuleLoader::class)->failures()['provider-budget'];
        $this->assertStringContainsString('needs Perysee 0.5.0', $reason);
        $this->assertFalse($this->events()->listening(StorageBudgetExceeded::class));
    }

    public function test_a_module_that_requires_the_release_of_the_event_can_listen_to_it(): void
    {
        config(['perysee.version' => '0.5.0']);
        $this->makeProviderModule('provider-budget-ok', '0.5.0', '$this->listen(\\App\\Modules\\Events\\StorageBudgetExceeded::class, fn () => null);');

        app(ModuleRegistry::class)->enable('provider-budget-ok');
        app(ModuleLoader::class)->load();

        $this->assertSame([], app(ModuleLoader::class)->failures());
        $this->assertTrue($this->events()->listening(StorageBudgetExceeded::class));
    }

    public function test_the_api_version_stays_one_and_a_module_needing_a_newer_core_is_refused_when_enabled(): void
    {
        $this->assertSame(1, ModuleApi::VERSION);
        $this->install('needs-events', enabled: false, manifest: ['core' => ModuleApi::DOMAIN_EVENTS_SINCE]);
        config(['perysee.version' => '0.3.0']);

        try {
            app(ModuleRegistry::class)->enable('needs-events');
            $this->fail('A Core older than the module requires must refuse it.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('requires Perysee '.ModuleApi::DOMAIN_EVENTS_SINCE, $exception->getMessage());
        }
        $this->assertDatabaseMissing('modules', ['id' => 'needs-events']);
    }

    private function makeProviderModule(string $id, string $core, string $bootBody): void
    {
        $segment = Str::studly($id);
        File::ensureDirectoryExists($this->modulesPath.'/'.$id.'/src');
        file_put_contents($this->modulesPath.'/'.$id.'/module.json', json_encode(['id' => $id, 'name' => $segment, 'version' => '1.0.0', 'core' => $core]));
        file_put_contents($this->modulesPath.'/'.$id.'/src/ModuleServiceProvider.php', <<<PHP
        <?php

        namespace Perysee\\Modules\\{$segment};

        use App\\Modules\\ModuleProvider;

        class ModuleServiceProvider extends ModuleProvider
        {
            public function boot(): void
            {
                {$bootBody}
            }
        }
        PHP);
    }
}
