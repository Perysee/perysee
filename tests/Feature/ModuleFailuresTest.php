<?php

namespace Tests\Feature;

use App\Installation\InstallationState;
use App\Models\User;
use App\Modules\ModuleExtensions;
use App\Modules\ModuleFailures;
use App\Modules\ModuleRegistry;
use App\Support\Coordination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class ModuleFailuresTest extends TestCase
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
            'perysee.version' => '0.1.1',
            'perysee.module_failures' => ['window' => 600, 'threshold' => ['stable' => 4, 'beta' => 3, 'experimental' => 2]],
        ]);
        Cache::flush();
        app()->forgetInstance(ModuleRegistry::class);
        app()->forgetInstance(ModuleFailures::class);
    }

    private function install(string $id, array $manifest = [], bool $enabled = true): void
    {
        File::ensureDirectoryExists($this->modulesPath.'/'.$id);
        file_put_contents($this->modulesPath.'/'.$id.'/module.json', json_encode($manifest + ['id' => $id, 'name' => ucfirst($id), 'version' => '1.0.0', 'core' => '0.1.0']));
        if ($enabled) {
            app(ModuleRegistry::class)->enable($id, acceptRisk: true);
        }
    }

    private function failures(): ModuleFailures
    {
        return app(ModuleFailures::class);
    }

    private function failTimes(string $id, int $times = 1): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->failures()->report($id, new RuntimeException('boom'), 'test');
        }
    }

    private function isEnabled(string $id): bool
    {
        app(ModuleRegistry::class)->refresh();

        return app(ModuleRegistry::class)->all()[$id]->enabled;
    }

    public function test_failures_below_the_threshold_are_counted_and_the_module_stays_enabled(): void
    {
        $this->install('flaky');

        $this->failTimes('flaky', 2);

        $this->assertTrue($this->isEnabled('flaky'));
        $this->assertSame(2, $this->failures()->total('flaky'));
        $this->assertNull($this->failures()->quarantine('flaky'));
    }

    public function test_reaching_the_threshold_quarantines_the_module_and_keeps_its_data(): void
    {
        $this->install('flaky');

        $this->failTimes('flaky', 3);

        $this->assertFalse($this->isEnabled('flaky'));
        $this->assertDatabaseHas('modules', ['id' => 'flaky', 'enabled' => false]);
        $quarantine = $this->failures()->quarantine('flaky');
        $this->assertNotNull($quarantine);
        $this->assertStringContainsString('boom', $quarantine['reason']);
        $this->assertNotEmpty($quarantine['at']);
    }

    public function test_the_threshold_depends_on_the_declared_stability(): void
    {
        $this->install('experimental-one', ['stability' => 'experimental']);
        $this->install('stable-one', ['stability' => 'stable']);

        $this->failTimes('experimental-one', 2);
        $this->failTimes('stable-one', 3);

        $this->assertFalse($this->isEnabled('experimental-one'));
        $this->assertTrue($this->isEnabled('stable-one'));
        $this->failTimes('stable-one');
        $this->assertFalse($this->isEnabled('stable-one'));
    }

    public function test_the_modules_that_need_a_quarantined_module_are_disabled_with_it(): void
    {
        $this->install('base');
        $this->install('addon', ['requires' => ['base']]);

        $this->failTimes('base', 3);

        $this->assertFalse($this->isEnabled('base'));
        $this->assertFalse($this->isEnabled('addon'));
        $this->assertStringContainsString('base', $this->failures()->quarantine('addon')['reason']);
    }

    public function test_enabling_a_module_again_releases_it_and_resets_its_window(): void
    {
        $this->install('flaky');
        $this->failTimes('flaky', 3);
        $this->assertFalse($this->isEnabled('flaky'));

        app(ModuleRegistry::class)->enable('flaky');

        $this->assertTrue($this->isEnabled('flaky'));
        $this->assertNull($this->failures()->quarantine('flaky'));
        $this->failTimes('flaky', 2);
        $this->assertTrue($this->isEnabled('flaky'), 'The window starts again after a manual enable.');
    }

    public function test_an_unknown_or_disabled_module_is_ignored_and_reporting_never_throws(): void
    {
        $this->install('idle', enabled: false);

        $this->failTimes('ghost', 10);
        $this->failTimes('idle', 10);

        $this->assertDatabaseMissing('modules', ['id' => 'ghost']);
        $this->assertDatabaseMissing('modules', ['id' => 'idle']);
    }

    public function test_a_page_data_resolver_that_keeps_failing_gets_its_module_quarantined(): void
    {
        $this->install('chatty');
        $extensions = app(ModuleExtensions::class);
        $extensions->shareData('chatty', fn (): array => throw new RuntimeException('cannot build the data'));

        for ($i = 0; $i < 3; $i++) {
            $extensions->data(Request::create('/'));
        }

        $this->assertFalse($this->isEnabled('chatty'));
        $this->assertStringContainsString('cannot build the data', $this->failures()->quarantine('chatty')['reason']);
    }

    public function test_an_exception_is_attributed_to_the_module_whose_file_raised_it(): void
    {
        $this->install('thrower');
        file_put_contents($this->modulesPath.'/thrower/Boom.php', "<?php\nreturn static function (): never { throw new \\RuntimeException('from the module'); };\n");
        $exception = null;
        try {
            (require $this->modulesPath.'/thrower/Boom.php')();
        } catch (\Throwable $caught) {
            $exception = $caught;
        }

        $this->assertSame('thrower', $this->failures()->moduleOf($exception));
        $this->assertNull($this->failures()->moduleOf(new RuntimeException('core')));
    }

    public function test_the_modules_page_and_the_health_page_show_failures_and_quarantine(): void
    {
        $this->install('flaky');
        $this->install('calm');
        $this->failTimes('flaky', 3);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get('/settings/modules')->assertInertia(fn (Assert $page) => $page
            ->where('installed.1.id', 'flaky')
            ->where('installed.1.quarantine.reason', fn ($reason) => str_contains($reason, 'boom'))
            ->where('installed.0.quarantine', null));

        $this->actingAs($admin)->get('/health')->assertInertia(fn (Assert $page) => $page
            ->has('moduleFailures', 1)
            ->where('moduleFailures.0.id', 'flaky')
            ->where('moduleFailures.0.failures', 3)
            ->where('moduleFailures.0.quarantined', true));
    }

    public function test_failures_use_the_replaceable_coordination_service(): void
    {
        $this->install('flaky');
        $coordination = new class implements Coordination
        {
            /** @var list<string> */
            public array $recorded = [];

            public function throttle(string $key, int $maxAttempts, int $decaySeconds): bool
            {
                return true;
            }

            public function failure(string $name): void
            {
                $this->recorded[] = $name;
            }

            public function failures(string $name): int
            {
                return count(array_keys($this->recorded, $name, true));
            }
        };
        $this->app->instance(Coordination::class, $coordination);
        app()->forgetInstance(ModuleFailures::class);

        $this->failTimes('flaky', 2);

        $this->assertSame(['module.flaky', 'module.flaky'], $coordination->recorded);
        $this->assertTrue($this->isEnabled('flaky'));
    }
}
