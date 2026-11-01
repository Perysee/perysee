<?php

namespace Tests\Feature;

use App\Installation\InstallationState;
use App\Models\User;
use App\Models\Website;
use App\Modules\ModuleLoader;
use App\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ModuleSystemRobustnessTest extends TestCase
{
    use RefreshDatabase;

    private string $modulesPath;

    protected function setUp(): void
    {
        parent::setUp();
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
        $this->modulesPath = $this->installationDirectory.'/modules';
        mkdir($this->modulesPath);
        config(['perysee.modules_path' => $this->modulesPath, 'perysee.version' => '0.1.1']);
        app()->forgetInstance(ModuleRegistry::class);
        app()->forgetInstance(ModuleLoader::class);
    }

    private function install(string $id, string $segment, string $boot = '', array $manifest = []): void
    {
        File::ensureDirectoryExists($this->modulesPath.'/'.$id.'/src');
        file_put_contents($this->modulesPath.'/'.$id.'/module.json', json_encode($manifest + ['id' => $id, 'name' => $id, 'version' => '1.0.0', 'core' => '0.1.0']));
        file_put_contents($this->modulesPath.'/'.$id.'/routes.php', "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::get('/', fn () => 'alive')->name('index');");
        file_put_contents($this->modulesPath.'/'.$id.'/src/ModuleServiceProvider.php', <<<PHP
        <?php

        namespace Perysee\\Modules\\{$segment};

        use App\\Modules\\ModuleProvider;

        class ModuleServiceProvider extends ModuleProvider
        {
            public function boot(): void
            {
                \$this->loadModuleRoutes(__DIR__.'/../routes.php');
                {$boot}
            }
        }
        PHP);
    }

    public function test_dependencies_load_before_the_modules_that_need_them(): void
    {
        $record = fn (string $id) => "app()->instance('order', [...(app()->bound('order') ? app('order') : []), '{$id}']);";
        $this->install('rob-addon', 'RobAddon', $record('addon'), ['requires' => ['rob-base']]);
        $this->install('rob-base', 'RobBase', $record('base'));
        $registry = app(ModuleRegistry::class);
        $registry->enable('rob-base');
        $registry->enable('rob-addon');

        app(ModuleLoader::class)->load();

        $this->assertSame(['base', 'addon'], app('order'));
    }

    public function test_the_application_keeps_working_when_the_core_was_updated_but_not_yet_migrated(): void
    {
        foreach (['module_sites', 'module_scopes', 'module_settings', 'modules'] as $table) {
            Schema::drop($table);
        }
        app()->forgetInstance(ModuleRegistry::class);
        $user = User::factory()->create(['is_admin' => true]);

        $this->get('/login')->assertOk();
        $this->actingAs($user)->get('/sites')->assertOk();
        $this->get('/settings/modules')->assertOk();
        $this->artisan('perysee:modules', ['action' => 'list'])->assertSuccessful();
    }

    public function test_the_module_system_adds_a_bounded_number_of_queries_to_a_page(): void
    {
        $user = User::factory()->create();
        $site = Website::factory()->create();
        $site->members()->attach($user, ['role' => 'owner']);
        $queries = function () use ($site, $user): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($user)->get('/sites/'.$site->id)->assertOk();

            return count(DB::getQueryLog());
        };
        $baseline = $queries();

        config(['perysee.modules_path' => base_path('modules')]);
        app()->forgetInstance(ModuleRegistry::class);
        app()->forgetInstance(ModuleLoader::class);
        app(ModuleRegistry::class)->enable('white-label');
        app(ModuleRegistry::class)->enable('annotations');
        app(ModuleLoader::class)->load();
        $withModules = $queries();

        $this->assertLessThanOrEqual($baseline + 12, $withModules, "A page went from {$baseline} to {$withModules} queries with two modules.");
    }

    public function test_one_module_failing_never_hides_the_others(): void
    {
        $this->install('rob-bad', 'RobBad', "throw new \\RuntimeException('nope');");
        $this->install('rob-good', 'RobGood');
        app(ModuleRegistry::class)->enable('rob-bad');
        app(ModuleRegistry::class)->enable('rob-good');
        app(ModuleLoader::class)->load();
        $user = User::factory()->create();

        $this->actingAs($user)->get('/modules/rob-good')->assertOk()->assertSee('alive');
        $this->get('/sites')->assertOk()->assertInertia(fn ($page) => $page->where('modules.active', ['rob-good']));
    }
}
