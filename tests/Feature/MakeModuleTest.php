<?php

namespace Tests\Feature;

use App\Installation\InstallationState;
use App\Models\User;
use App\Modules\Manifest;
use App\Modules\ModuleLoader;
use App\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MakeModuleTest extends TestCase
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

    public function test_it_scaffolds_a_module_with_a_valid_manifest(): void
    {
        $this->artisan('perysee:make-module', ['id' => 'scaffold-files', '--name' => 'Scaffold files'])->assertSuccessful();

        $manifest = Manifest::fromFile($this->modulesPath.'/scaffold-files/module.json');
        $this->assertSame('Scaffold files', $manifest->name);
        $this->assertSame(config('perysee.version'), $manifest->core);
        foreach (['src/ModuleServiceProvider.php', 'routes.php', 'dist/module.js', 'README.md'] as $file) {
            $this->assertFileExists($this->modulesPath.'/scaffold-files/'.$file);
        }
        $this->assertStringContainsString('namespace Perysee\\Modules\\ScaffoldFiles;', file_get_contents($this->modulesPath.'/scaffold-files/src/ModuleServiceProvider.php'));
        $this->assertStringNotContainsString('{{', file_get_contents($this->modulesPath.'/scaffold-files/dist/module.js'));
    }

    public function test_an_unsafe_id_or_an_existing_module_is_refused_without_writing_anything(): void
    {
        $this->artisan('perysee:make-module', ['id' => '../escape'])->assertFailed();
        $this->assertSame([], glob($this->modulesPath.'/*'));

        $this->artisan('perysee:make-module', ['id' => 'scaffold-twice'])->assertSuccessful();
        file_put_contents($this->modulesPath.'/scaffold-twice/keep.txt', 'mine');
        $this->artisan('perysee:make-module', ['id' => 'scaffold-twice'])->expectsOutputToContain('already exists')->assertFailed();
        $this->assertFileExists($this->modulesPath.'/scaffold-twice/keep.txt');
    }

    public function test_a_scaffolded_module_works_end_to_end_once_enabled(): void
    {
        $this->artisan('perysee:make-module', ['id' => 'scaffold-live'])->assertSuccessful();
        $this->artisan('perysee:modules', ['action' => 'enable', 'module' => 'scaffold-live'])->assertSuccessful();
        app(ModuleLoader::class)->load();

        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get('/modules/scaffold-live')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('screen', 'module:scaffold-live')
                ->where('modules.scripts', [url('/modules/scaffold-live/assets/module.js').'?v=0.1.0'])
                ->where('modules.navigation', ['workspace' => [], 'admin' => [], 'site' => []]));
        $this->get('/settings/modules/scaffold-live')->assertInertia(fn (Assert $page) => $page
            ->where('modules.data.scaffold-live.message', 'Hello from Scaffold Live.'));
        $this->get('/modules/scaffold-live/assets/module.js')->assertOk();
        $this->assertSame([], app(ModuleLoader::class)->failures());
    }
}
