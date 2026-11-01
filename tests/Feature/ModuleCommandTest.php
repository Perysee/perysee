<?php

namespace Tests\Feature;

use App\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ModuleCommandTest extends TestCase
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
        File::ensureDirectoryExists($this->modulesPath.'/cmd-mod');
        file_put_contents($this->modulesPath.'/cmd-mod/module.json', json_encode(['id' => 'cmd-mod', 'name' => 'Command module', 'version' => '2.0.0', 'core' => '0.1.0']));
    }

    public function test_list_shows_installed_modules_with_their_state(): void
    {
        $this->artisan('perysee:modules', ['action' => 'list'])
            ->expectsOutputToContain('cmd-mod 2.0.0 disabled')
            ->assertSuccessful();
    }

    public function test_enable_then_disable_changes_the_stored_state(): void
    {
        $this->artisan('perysee:modules', ['action' => 'enable', 'module' => 'cmd-mod'])->assertSuccessful();
        $this->assertDatabaseHas('modules', ['id' => 'cmd-mod', 'enabled' => true]);

        $this->artisan('perysee:modules', ['action' => 'disable', 'module' => 'cmd-mod'])->assertSuccessful();
        $this->assertDatabaseHas('modules', ['id' => 'cmd-mod', 'enabled' => false]);
    }

    public function test_registry_errors_are_reported_as_failures(): void
    {
        $this->artisan('perysee:modules', ['action' => 'enable', 'module' => 'ghost'])
            ->expectsOutputToContain('is not installed')
            ->assertFailed();
    }

    public function test_enable_and_disable_require_a_module_id(): void
    {
        $this->artisan('perysee:modules', ['action' => 'enable'])->assertFailed();
    }

    public function test_uninstall_asks_for_force_and_refuses_an_enabled_module(): void
    {
        $this->artisan('perysee:modules', ['action' => 'enable', 'module' => 'cmd-mod'])->assertSuccessful();

        $this->artisan('perysee:modules', ['action' => 'uninstall', 'module' => 'cmd-mod'])->expectsOutputToContain('--force')->assertFailed();
        $this->artisan('perysee:modules', ['action' => 'uninstall', 'module' => 'cmd-mod', '--force' => true])->expectsOutputToContain('disable')->assertFailed();
        $this->assertDatabaseHas('modules', ['id' => 'cmd-mod', 'enabled' => true]);

        $this->artisan('perysee:modules', ['action' => 'disable', 'module' => 'cmd-mod'])->assertSuccessful();
        $this->artisan('perysee:modules', ['action' => 'uninstall', 'module' => 'cmd-mod', '--force' => true])->assertSuccessful();
        $this->assertDatabaseMissing('modules', ['id' => 'cmd-mod']);
    }
}
