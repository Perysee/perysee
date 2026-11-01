<?php

namespace Tests\Feature;

use App\Installation\InstallationState;
use App\Models\User;
use App\Models\Website;
use App\Modules\ModuleLoader;
use App\Modules\ModuleRegistry;
use App\Modules\ModuleScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ModuleSettingsPageTest extends TestCase
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
        app()->forgetInstance(ModuleScope::class);
        File::ensureDirectoryExists($this->modulesPath.'/alpha');
        file_put_contents($this->modulesPath.'/alpha/module.json', json_encode(['id' => 'alpha', 'name' => 'Alpha', 'version' => '1.2.0', 'core' => '0.1.0', 'description' => 'The alpha module.']));
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    /**
     * @return array{0: User, 1: Website, 2: Website}
     */
    private function adminWithSites(): array
    {
        $admin = $this->admin();
        [$one, $two] = Website::factory()->count(2)->create()->all();
        $one->members()->attach($admin, ['role' => 'owner']);
        $two->members()->attach($admin, ['role' => 'viewer']);

        return [$admin, $one, $two];
    }

    public function test_only_administrators_open_a_module_settings_page_and_unknown_modules_are_404(): void
    {
        $this->get('/settings/modules/alpha')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/settings/modules/alpha')->assertForbidden();
        $this->actingAs($this->admin())->get('/settings/modules/ghost')->assertNotFound();
        $this->get('/settings/modules/alpha')->assertOk()->assertInertia(fn (Assert $page) => $page->where('screen', 'modules.settings'));
    }

    public function test_the_page_describes_the_module_its_scope_and_the_sites_the_administrator_can_reach(): void
    {
        [$admin, $one, $two] = $this->adminWithSites();
        Website::factory()->create(['name' => 'Not mine']);
        app(ModuleScope::class)->limitTo('alpha', [$two->id]);

        $this->actingAs($admin)->get('/settings/modules/alpha')->assertInertia(fn (Assert $page) => $page
            ->where('module.id', 'alpha')
            ->where('module.name', 'Alpha')
            ->where('module.installed', false)
            ->where('scope', ['all' => false, 'site_ids' => [$two->id], 'hidden' => 0])
            ->has('siteChoices', 2)
            ->where('siteChoices.0.id', fn ($id) => in_array($id, [$one->id, $two->id], true))
            ->where('moduleLinks.save', route('modules.scope', 'alpha'))
            ->where('moduleLinks.back', route('modules.index'))
            ->where('links.dashboard', route('dashboard')));
    }

    public function test_selected_sites_the_administrator_cannot_see_are_counted_not_listed(): void
    {
        [$admin, $one] = $this->adminWithSites();
        $foreign = Website::factory()->create(['name' => 'Foreign client']);
        app(ModuleScope::class)->limitTo('alpha', [$one->id, $foreign->id]);

        $response = $this->actingAs($admin)->get('/settings/modules/alpha');

        $response->assertInertia(fn (Assert $page) => $page->where('scope.hidden', 1)->where('scope.site_ids', [$one->id]));
        $this->assertStringNotContainsString('Foreign client', $response->getContent());
    }

    public function test_the_administrator_limits_a_module_to_chosen_sites(): void
    {
        [$admin, $one, $two] = $this->adminWithSites();
        $this->actingAs($admin);

        $this->post('/settings/modules/alpha/scope', ['scope' => 'sites', 'sites' => [$one->id]])
            ->assertRedirect(route('modules.settings', 'alpha'))
            ->assertSessionHas('status');

        $this->assertFalse(app(ModuleScope::class)->coversAllSites('alpha'));
        $this->assertSame([$one->id], app(ModuleScope::class)->siteIds('alpha'));
        $this->assertFalse(app(ModuleScope::class)->isActiveFor('alpha', $two));
    }

    public function test_saving_keeps_the_selected_sites_the_administrator_cannot_see(): void
    {
        [$admin, $one, $two] = $this->adminWithSites();
        $foreign = Website::factory()->create();
        app(ModuleScope::class)->limitTo('alpha', [$one->id, $foreign->id]);

        $this->actingAs($admin)->post('/settings/modules/alpha/scope', ['scope' => 'sites', 'sites' => [$two->id]])->assertSessionHasNoErrors();

        $this->assertEqualsCanonicalizing([$two->id, $foreign->id], app(ModuleScope::class)->siteIds('alpha'));
    }

    public function test_sites_outside_the_administrator_reach_cannot_be_added(): void
    {
        [$admin] = $this->adminWithSites();
        $foreign = Website::factory()->create();

        $this->actingAs($admin)->post('/settings/modules/alpha/scope', ['scope' => 'sites', 'sites' => [$foreign->id]])->assertSessionHasErrors('sites.0');

        $this->assertTrue(app(ModuleScope::class)->coversAllSites('alpha'));
    }

    public function test_choosing_the_whole_instance_clears_the_selection(): void
    {
        [$admin, $one] = $this->adminWithSites();
        app(ModuleScope::class)->limitTo('alpha', [$one->id]);

        $this->actingAs($admin)->post('/settings/modules/alpha/scope', ['scope' => 'all'])->assertSessionHas('status');

        $this->assertTrue(app(ModuleScope::class)->coversAllSites('alpha'));
        $this->assertDatabaseCount('module_sites', 0);
    }

    public function test_a_site_selection_may_be_empty_and_the_scope_must_be_valid(): void
    {
        $this->actingAs($this->admin());

        $this->post('/settings/modules/alpha/scope', ['scope' => 'sites'])->assertSessionHasNoErrors();
        $this->assertFalse(app(ModuleScope::class)->coversAllSites('alpha'));
        $this->assertSame([], app(ModuleScope::class)->siteIds('alpha'));

        $this->post('/settings/modules/alpha/scope', ['scope' => 'nowhere'])->assertSessionHasErrors('scope');
        $this->post('/settings/modules/ghost/scope', ['scope' => 'all'])->assertNotFound();
    }

    public function test_members_cannot_change_the_scope(): void
    {
        $this->actingAs(User::factory()->create())->post('/settings/modules/alpha/scope', ['scope' => 'sites'])->assertForbidden();

        $this->assertTrue(app(ModuleScope::class)->coversAllSites('alpha'));
    }

    public function test_the_modules_list_links_each_module_to_its_settings_and_shows_its_scope(): void
    {
        [$admin, $one] = $this->adminWithSites();
        app(ModuleScope::class)->limitTo('alpha', [$one->id]);

        $this->actingAs($admin)->get('/settings/modules')->assertInertia(fn (Assert $page) => $page
            ->where('installed.0.links.settings', route('modules.settings', 'alpha'))
            ->where('installed.0.scope', ['all' => false, 'count' => 1]));
    }

    public function test_every_navigation_link_offered_on_a_module_settings_page_leads_somewhere(): void
    {
        [$admin, $one] = $this->adminWithSites();
        $this->actingAs($admin);
        $props = $this->get('/settings/modules/alpha')->assertOk()->viewData('page')['props'];

        $internal = collect($props['links'])->only(['dashboard', 'sites.index', 'health', 'settings', 'modules', 'password.edit', 'sites.create']);
        $this->assertCount(7, $internal);
        foreach ($internal as $name => $url) {
            $this->get($url)->assertOk("The link \"{$name}\" ({$url}) does not open from a module settings page.");
        }
        foreach ($props['navigation'] as $site) {
            $this->get($site['url'])->assertOk();
        }
        $this->assertSame($one->id, $props['navigation'][0]['id'] ?? $one->id);
    }
}
