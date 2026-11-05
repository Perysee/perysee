<?php

namespace Tests\Feature;

use App\Installation\InstallationState;
use App\Models\User;
use App\Modules\ModuleLoader;
use App\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ModuleAdminPageTest extends TestCase
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

    private function install(string $id, array $manifest = []): void
    {
        File::ensureDirectoryExists($this->modulesPath.'/'.$id);
        file_put_contents($this->modulesPath.'/'.$id.'/module.json', json_encode($manifest + [
            'id' => $id, 'name' => ucfirst($id), 'version' => '1.0.0', 'core' => '0.1.0', 'description' => "The {$id} module.",
        ]));
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_the_list_exposes_each_stability_level_and_defaults_to_beta(): void
    {
        $this->install('alpha', ['stability' => 'stable']);
        $this->install('beta-one');
        $this->install('lab', ['stability' => 'experimental']);

        $this->actingAs($this->admin())->get('/settings/modules')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('installed.0.stability', 'stable')
            ->where('installed.1.stability', 'beta')
            ->where('installed.2.stability', 'experimental'));
    }

    public function test_an_experimental_module_is_enabled_only_after_the_risk_is_accepted(): void
    {
        $this->install('lab', ['stability' => 'experimental']);
        $this->actingAs($this->admin());

        $this->post('/settings/modules/lab/enable')->assertRedirect(route('modules.index'))->assertSessionHas('error');
        $this->assertDatabaseMissing('modules', ['id' => 'lab']);

        $this->post('/settings/modules/lab/enable', ['accept_risk' => '1'])->assertRedirect(route('modules.index'))->assertSessionHas('status');
        $this->assertDatabaseHas('modules', ['id' => 'lab', 'enabled' => true]);
    }

    public function test_the_page_says_when_modules_are_switched_off(): void
    {
        $this->install('alpha');
        $admin = $this->admin();

        $this->actingAs($admin)->get('/settings/modules')->assertInertia(fn (Assert $page) => $page->where('modulesEnabled', true));

        config(['perysee.modules_enabled' => false]);
        $this->actingAs($admin)->get('/settings/modules')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('modulesEnabled', false)
            ->where('installed.0.id', 'alpha'));
    }

    public function test_only_administrators_can_open_the_page(): void
    {
        $this->get('/settings/modules')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/settings/modules')->assertForbidden();
        $this->actingAs($this->admin())->get('/settings/modules')->assertOk()->assertInertia(fn (Assert $page) => $page->where('screen', 'modules'));
    }

    public function test_it_lists_installed_modules_with_their_state_and_compatibility(): void
    {
        $this->install('alpha');
        $this->install('future', ['core' => '9.0.0']);
        $this->install('addon', ['requires' => ['alpha']]);
        app(ModuleRegistry::class)->enable('alpha');

        $this->actingAs($this->admin())->get('/settings/modules')->assertInertia(fn (Assert $page) => $page
            ->has('installed', 3)
            ->where('installed.0', fn ($module) => $module['id'] === 'addon' && $module['enabled'] === false && $module['compatible'] === true && $module['missing_dependencies'] === [])
            ->where('installed.1', fn ($module) => $module['id'] === 'alpha' && $module['enabled'] === true && $module['dependents'] === [] && $module['links']['disable'] === route('modules.disable', 'alpha', false))
            ->where('installed.2', fn ($module) => $module['id'] === 'future' && $module['compatible'] === false && $module['core'] === '9.0.0'));
    }

    public function test_it_reports_missing_dependencies_and_rejected_directories(): void
    {
        $this->install('addon', ['requires' => ['alpha']]);
        mkdir($this->modulesPath.'/broken');
        file_put_contents($this->modulesPath.'/broken/module.json', '{nope');

        $this->actingAs($this->admin())->get('/settings/modules')->assertInertia(fn (Assert $page) => $page
            ->where('installed.0.missing_dependencies', ['alpha'])
            ->where('invalid', fn ($invalid) => count($invalid) === 1 && $invalid[0]['directory'] === 'broken'));
    }

    public function test_an_administrator_enables_and_disables_a_module(): void
    {
        $this->install('alpha');
        $this->actingAs($this->admin());

        $this->post('/settings/modules/alpha/enable')->assertRedirect(route('modules.index'))->assertSessionHas('status');
        $this->assertDatabaseHas('modules', ['id' => 'alpha', 'enabled' => true]);

        $this->post('/settings/modules/alpha/disable')->assertRedirect(route('modules.index'))->assertSessionHas('status');
        $this->assertDatabaseHas('modules', ['id' => 'alpha', 'enabled' => false]);
    }

    public function test_a_refused_change_explains_why_and_changes_nothing(): void
    {
        $this->install('future', ['core' => '9.0.0']);
        $this->install('base');
        $this->install('addon', ['requires' => ['base']]);
        $this->actingAs($this->admin());

        $this->post('/settings/modules/future/enable')->assertRedirect(route('modules.index'))
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'requires Perysee 9.0.0'));
        $this->post('/settings/modules/ghost/enable')->assertRedirect(route('modules.index'))->assertSessionHas('error');
        $this->assertDatabaseMissing('modules', ['id' => 'future']);

        app(ModuleRegistry::class)->enable('base');
        app(ModuleRegistry::class)->enable('addon');
        $this->post('/settings/modules/base/disable')->assertSessionHas('error', fn (string $message) => str_contains($message, 'addon'));
        $this->assertDatabaseHas('modules', ['id' => 'base', 'enabled' => true]);
    }

    public function test_members_cannot_change_modules(): void
    {
        $this->install('alpha');

        $this->actingAs(User::factory()->create())->post('/settings/modules/alpha/enable')->assertForbidden();
        $this->assertDatabaseMissing('modules', ['id' => 'alpha']);
    }

    public function test_the_admin_navigation_links_to_the_page(): void
    {
        $this->actingAs($this->admin())->get('/settings')->assertInertia(fn (Assert $page) => $page->where('links.modules', '/settings/modules'));
    }
}
