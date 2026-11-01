<?php

namespace Tests\Feature;

use App\Modules\ModuleLoader;
use App\Modules\ModuleRegistry;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ModuleBootIsolationTest extends TestCase
{
    private string $database;

    /** @var array<string, array{0: string|false, 1: mixed, 2: mixed}> the environment as the test run set it */
    private array $environment = [];

    protected function setUp(): void
    {
        parent::setUp();
        // These tests start a second application on a throwaway SQLite file: they only make sense when the suite itself runs on SQLite.
        if (config('database.default') !== 'sqlite') {
            $this->markTestSkipped('Needs a throwaway SQLite database.');
        }
        foreach (['DB_DATABASE', 'PERYSEE_MODULES_PATH'] as $key) {
            $this->environment[$key] = [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null];
        }
        $this->database = $this->installationDirectory.'/modules.sqlite';
        touch($this->database);
    }

    private function useEnvironment(array $values): void
    {
        foreach ($values as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $_SERVER[$key] = $value;
        }
    }

    public function test_an_application_still_boots_when_an_enabled_module_fails_while_booting(): void
    {
        $modules = $this->installationDirectory.'/modules';
        mkdir($modules.'/boot-bad/src', 0777, true);
        file_put_contents($modules.'/boot-bad/module.json', json_encode(['id' => 'boot-bad', 'name' => 'Bad', 'version' => '1.0.0', 'core' => '0.1.0']));
        file_put_contents($modules.'/boot-bad/src/ModuleServiceProvider.php', <<<'PHP'
        <?php

        namespace Perysee\Modules\BootBad;

        use App\Modules\ModuleProvider;

        class ModuleServiceProvider extends ModuleProvider
        {
            public function boot(): void
            {
                $this->callAMethodThatNoLongerExists();
            }
        }
        PHP);

        // Persist the enabled state in a file database that a second application can read.
        config(['database.connections.sqlite.database' => $this->database, 'perysee.modules_path' => $modules, 'perysee.version' => '0.1.1']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
        app()->forgetInstance(ModuleRegistry::class);
        app(ModuleRegistry::class)->enable('boot-bad');

        $this->useEnvironment(['DB_DATABASE' => $this->database, 'PERYSEE_MODULES_PATH' => $modules]);
        $application = $this->createApplication();

        $failures = $application->make(ModuleLoader::class)->failures();
        $this->assertArrayHasKey('boot-bad', $failures);
        $this->assertStringContainsString('callAMethodThatNoLongerExists', $failures['boot-bad']);
    }

    public function test_a_disabled_module_is_gone_for_the_next_application_including_its_routes(): void
    {
        $modules = $this->installationDirectory.'/modules';
        mkdir($modules.'/boot-gone/src', 0777, true);
        file_put_contents($modules.'/boot-gone/module.json', json_encode(['id' => 'boot-gone', 'name' => 'Gone', 'version' => '1.0.0', 'core' => '0.1.0']));
        file_put_contents($modules.'/boot-gone/routes.php', "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::get('/', fn () => 'alive')->name('index');");
        file_put_contents($modules.'/boot-gone/src/ModuleServiceProvider.php', <<<'PHP'
        <?php

        namespace Perysee\Modules\BootGone;

        use App\Modules\ModuleProvider;

        class ModuleServiceProvider extends ModuleProvider
        {
            public function boot(): void
            {
                $this->loadModuleRoutes(__DIR__.'/../routes.php');
            }
        }
        PHP);
        config(['database.connections.sqlite.database' => $this->database, 'perysee.modules_path' => $modules, 'perysee.version' => '0.1.1']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
        app()->forgetInstance(ModuleRegistry::class);
        app(ModuleRegistry::class)->enable('boot-gone');
        $this->useEnvironment(['DB_DATABASE' => $this->database, 'PERYSEE_MODULES_PATH' => $modules]);

        $enabled = $this->createApplication();
        $this->assertTrue($enabled['router']->has('boot-gone.index'));

        app(ModuleRegistry::class)->disable('boot-gone');
        $disabled = $this->createApplication();
        $this->assertFalse($disabled['router']->has('boot-gone.index'));
    }

    protected function tearDown(): void
    {
        // Put the environment back exactly as it was, so the next tests of the run, on SQLite or MariaDB, see their own database.
        foreach ($this->environment as $key => [$process, $env, $server]) {
            $process === false ? putenv($key) : putenv("{$key}={$process}");
            $env === null ? Arr::forget($_ENV, $key) : $_ENV[$key] = $env;
            $server === null ? Arr::forget($_SERVER, $key) : $_SERVER[$key] = $server;
        }
        parent::tearDown();
    }
}
