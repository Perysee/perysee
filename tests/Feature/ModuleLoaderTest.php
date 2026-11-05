<?php

namespace Tests\Feature;

use App\Modules\ModuleLoader;
use App\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class ModuleLoaderTest extends TestCase
{
    use RefreshDatabase;

    private string $modulesPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->modulesPath = $this->installationDirectory.'/modules';
        mkdir($this->modulesPath);
        config(['perysee.modules_path' => $this->modulesPath, 'perysee.version' => '0.1.1']);
        app()->forgetInstance(ModuleRegistry::class);
        app()->forgetInstance(ModuleLoader::class);
    }

    /**
     * Writes a module whose provider runs $bootBody. Ids are unique per test because classes cannot be redeclared.
     */
    private function makeModule(string $id, string $bootBody = '', array $manifest = []): string
    {
        $segment = Str::studly($id);
        File::ensureDirectoryExists($this->modulesPath.'/'.$id.'/src');
        file_put_contents($this->modulesPath.'/'.$id.'/module.json', json_encode($manifest + [
            'id' => $id, 'name' => $segment, 'version' => '1.0.0', 'core' => '0.1.0',
        ]));
        file_put_contents($this->modulesPath.'/'.$id.'/src/ModuleServiceProvider.php', <<<PHP
        <?php

        namespace Perysee\\Modules\\{$segment};

        use Illuminate\\Support\\ServiceProvider;

        class ModuleServiceProvider extends ServiceProvider
        {
            public function boot(): void
            {
                {$bootBody}
            }
        }
        PHP);

        return $segment;
    }

    private function load(string ...$ids): void
    {
        foreach ($ids as $id) {
            app(ModuleRegistry::class)->enable($id);
        }
        app(ModuleLoader::class)->load();
    }

    public function test_enabled_modules_are_autoloaded_and_their_provider_boots(): void
    {
        $this->makeModule('loader-ok', "app()->instance('loader-ok.booted', true);");

        $this->load('loader-ok');

        $this->assertTrue(app('loader-ok.booted'));
        $this->assertSame([], app(ModuleLoader::class)->failures());
    }

    public function test_disabled_modules_are_never_loaded(): void
    {
        $this->makeModule('loader-off', "app()->instance('loader-off.booted', true);");

        app(ModuleLoader::class)->load();

        $this->assertFalse(app()->bound('loader-off.booted'));
        $this->assertFalse(class_exists('Perysee\\Modules\\LoaderOff\\ModuleServiceProvider', false));
    }

    public function test_the_modules_switch_loads_no_module_even_when_some_are_enabled(): void
    {
        $this->makeModule('loader-paused', "app()->instance('loader-paused.booted', true);");
        app(ModuleRegistry::class)->enable('loader-paused');
        config(['perysee.modules_enabled' => false]);

        app(ModuleLoader::class)->load();

        $this->assertFalse(app()->bound('loader-paused.booted'));
        $this->assertFalse(class_exists('Perysee\\Modules\\LoaderPaused\\ModuleServiceProvider', false));
        $this->assertSame([], app(ModuleLoader::class)->failures());
        $this->assertDatabaseHas('modules', ['id' => 'loader-paused', 'enabled' => true]);
    }

    public function test_a_failing_module_is_isolated_and_reported(): void
    {
        $this->makeModule('loader-bad', "throw new \\RuntimeException('boom');");
        $this->makeModule('loader-good', "app()->instance('loader-good.booted', true);");

        $this->load('loader-bad', 'loader-good');

        $this->assertTrue(app('loader-good.booted'));
        $this->assertSame(['loader-bad' => 'boom'], app(ModuleLoader::class)->failures());
    }

    public function test_a_module_with_a_syntax_error_does_not_break_the_application(): void
    {
        $this->makeModule('loader-parse', 'this is not php');
        $this->makeModule('loader-fine', "app()->instance('loader-fine.booted', true);");

        $this->load('loader-parse', 'loader-fine');

        $this->assertTrue(app('loader-fine.booted'));
        $this->assertArrayHasKey('loader-parse', app(ModuleLoader::class)->failures());
    }

    public function test_a_module_without_a_provider_class_is_reported(): void
    {
        $this->makeModule('loader-empty');
        unlink($this->modulesPath.'/loader-empty/src/ModuleServiceProvider.php');

        $this->load('loader-empty');

        $this->assertStringContainsString('ModuleServiceProvider', app(ModuleLoader::class)->failures()['loader-empty']);
    }
}
