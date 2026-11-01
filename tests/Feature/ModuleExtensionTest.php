<?php

namespace Tests\Feature;

use App\Installation\InstallationState;
use App\Models\User;
use App\Models\Website;
use App\Modules\ModuleLoader;
use App\Modules\ModuleRegistry;
use App\Modules\ModuleScope;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ModuleExtensionTest extends TestCase
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

    /**
     * Installs a module whose provider runs $boot. Ids are unique per test because provider classes cannot be redeclared.
     */
    private function install(string $id, string $segment, string $boot = '', string $routes = '', array $files = []): void
    {
        foreach ($files as $path => $contents) {
            File::ensureDirectoryExists(dirname($this->modulesPath.'/'.$id.'/'.$path));
            file_put_contents($this->modulesPath.'/'.$id.'/'.$path, $contents);
        }
        File::ensureDirectoryExists($this->modulesPath.'/'.$id.'/src');
        file_put_contents($this->modulesPath.'/'.$id.'/module.json', json_encode([
            'id' => $id, 'name' => 'Extension', 'version' => '1.0.0', 'core' => '0.1.0',
        ]));
        file_put_contents($this->modulesPath.'/'.$id.'/routes.php', "<?php\n\nuse App\\Support\\Ui;\nuse Illuminate\\Support\\Facades\\Route;\n\n".($routes ?: "Route::get('/', fn () => Ui::render('module:greeter', ['greeting' => 'Hello']))->name('index');"));
        file_put_contents($this->modulesPath.'/'.$id.'/src/ModuleServiceProvider.php', <<<PHP
        <?php

        namespace Perysee\\Modules\\{$segment};

        use App\\Modules\\ModuleProvider;

        class ModuleServiceProvider extends ModuleProvider
        {
            public function boot(): void
            {
                {$boot}
            }
        }
        PHP);
    }

    private function enable(string $id): void
    {
        app(ModuleRegistry::class)->enable($id);
        app(ModuleLoader::class)->load();
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_an_enabled_module_serves_its_routes_under_its_own_prefix_to_signed_in_users(): void
    {
        $this->install('ext-routes', 'ExtRoutes', "\$this->loadModuleRoutes(__DIR__.'/../routes.php');");
        $this->enable('ext-routes');

        $this->get('/modules/ext-routes')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/modules/ext-routes')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('screen', 'module:greeter')->where('greeting', 'Hello'));
    }

    public function test_public_routes_need_no_session_and_sit_under_the_public_prefix(): void
    {
        $this->install('ext-public', 'ExtPublic', "\$this->loadPublicRoutes(__DIR__.'/../routes.php');", "Route::get('/ping', fn () => 'pong')->name('ping');");
        $this->enable('ext-public');

        $this->get('/modules/ext-public/public/ping')->assertOk()->assertSee('pong');
    }

    public function test_a_disabled_module_has_no_routes(): void
    {
        $this->install('ext-off', 'ExtOff', "\$this->loadModuleRoutes(__DIR__.'/../routes.php');");

        $this->actingAs(User::factory()->create())->get('/modules/ext-off')->assertNotFound();
    }

    public function test_a_module_without_navigation_adds_nothing_to_the_interface(): void
    {
        $this->install('ext-quiet', 'ExtQuiet', "\$this->loadModuleRoutes(__DIR__.'/../routes.php');");
        $this->enable('ext-quiet');

        $this->actingAs(User::factory()->create())->get('/sites')->assertInertia(fn (Assert $page) => $page
            ->where('modules.navigation', ['workspace' => [], 'admin' => [], 'site' => []])
            ->where('modules.data', []));
    }

    public function test_navigation_entries_are_placed_in_their_zone_and_admin_entries_stay_hidden_from_members(): void
    {
        $this->install('ext-nav', 'ExtNav', <<<'BOOT'
        $this->loadModuleRoutes(__DIR__.'/../routes.php');
                $this->addNavigation('workspace', 'Greeter', 'i-tabler-hand-stop', 'ext-nav.index');
                $this->addNavigation('admin', 'Greeter admin', 'i-tabler-settings', 'ext-nav.index');
        BOOT);
        $this->enable('ext-nav');

        $this->actingAs($this->admin())->get('/sites')->assertInertia(fn (Assert $page) => $page
            ->where('modules.navigation.workspace.0', fn ($item) => $item['label'] === 'Greeter' && $item['icon'] === 'i-tabler-hand-stop' && $item['url'] === '/modules/ext-nav')
            ->where('modules.navigation.admin.0.label', 'Greeter admin'));
        $this->actingAs(User::factory()->create())->get('/sites')->assertInertia(fn (Assert $page) => $page
            ->where('modules.navigation.workspace.0.label', 'Greeter')
            ->where('modules.navigation.admin', []));
    }

    public function test_an_unknown_navigation_zone_is_refused(): void
    {
        $this->install('ext-zone', 'ExtZone', "\$this->addNavigation('footer', 'x', 'i-tabler-x', 'ext-zone.index');");
        $this->enable('ext-zone');

        $this->assertStringContainsString('zone', app(ModuleLoader::class)->failures()['ext-zone']);
    }

    public function test_module_data_is_shared_under_the_module_id_with_the_request_user(): void
    {
        $this->install('ext-data', 'ExtData', "\$this->shareData(fn (\$request) => ['for' => \$request->user()->email, 'answer' => 42]);");
        $this->enable('ext-data');
        $user = User::factory()->create();

        $this->actingAs($user)->get('/sites')->assertInertia(fn (Assert $page) => $page
            ->where('modules.data.ext-data', ['for' => $user->email, 'answer' => 42]));
    }

    public function test_module_data_is_never_shared_with_guests(): void
    {
        $this->install('ext-guest', 'ExtGuest', "\$this->shareData(fn () => ['secret' => 'x']);");
        $this->enable('ext-guest');

        $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('modules.data', []));
    }

    public function test_branding_defaults_to_perysee_for_everyone_including_guests(): void
    {
        $this->get('/login')->assertInertia(fn (Assert $page) => $page
            ->where('branding', ['name' => 'Perysee', 'logo' => null, 'accent' => null]));
    }

    public function test_a_module_can_override_the_branding_and_the_core_keeps_only_safe_values(): void
    {
        $this->install('ext-brand', 'ExtBrand', <<<'BOOT'
        $this->brand(fn () => [
                    'name' => '  Acme Analytics  ',
                    'logo' => 'https://cdn.example.com/logo.svg',
                    'accent' => '#12ab34',
                    'script' => '<script>alert(1)</script>',
                ]);
        BOOT);
        $this->enable('ext-brand');

        $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('branding', [
            'name' => 'Acme Analytics', 'logo' => 'https://cdn.example.com/logo.svg', 'accent' => '#12ab34',
        ]));
    }

    public function test_unsafe_branding_values_fall_back_to_the_defaults(): void
    {
        $this->install('ext-bad', 'ExtBad', <<<'BOOT'
        $this->brand(fn () => ['name' => str_repeat('x', 100), 'logo' => 'javascript:alert(1)', 'accent' => 'red; background:url(x)']);
        BOOT);
        $this->enable('ext-bad');

        $this->get('/login')->assertInertia(fn (Assert $page) => $page
            ->where('branding', ['name' => 'Perysee', 'logo' => null, 'accent' => null]));
    }

    public function test_a_failing_branding_resolver_never_breaks_pages(): void
    {
        $this->install('ext-boom', 'ExtBoom', "\$this->brand(fn () => throw new \\RuntimeException('boom'));");
        $this->enable('ext-boom');

        $this->get('/login')->assertOk()->assertInertia(fn (Assert $page) => $page->where('branding.name', 'Perysee'));
    }

    private function memberWithSites(int $count = 2): array
    {
        $user = User::factory()->create();
        $sites = Website::factory()->count($count)->create();
        foreach ($sites as $site) {
            $site->members()->attach($user, ['role' => 'owner']);
        }

        return [$user, ...$sites->all()];
    }

    public function test_a_module_route_with_a_website_is_refused_on_sites_outside_the_module_scope(): void
    {
        $this->install('ext-scope', 'ExtScope', "\$this->loadModuleRoutes(__DIR__.'/../routes.php');", "Route::get('/sites/{website}', fn (\\App\\Models\\Website \$website) => 'site '.\$website->id)->name('site');");
        $this->enable('ext-scope');
        [$user, $inside, $outside] = $this->memberWithSites();
        app(ModuleScope::class)->limitTo('ext-scope', [$inside->id]);

        $this->actingAs($user)->get('/modules/ext-scope/sites/'.$inside->id)->assertOk()->assertSee('site '.$inside->id);
        $this->get('/modules/ext-scope/sites/'.$outside->id)->assertNotFound();

        app(ModuleScope::class)->allowAllSites('ext-scope');
        $this->get('/modules/ext-scope/sites/'.$outside->id)->assertOk();
    }

    public function test_the_active_modules_follow_the_site_being_viewed(): void
    {
        $this->install('ext-active', 'ExtActive');
        $this->enable('ext-active');
        [$user, $inside, $outside] = $this->memberWithSites();
        app(ModuleScope::class)->limitTo('ext-active', [$inside->id]);

        $this->actingAs($user)->get('/sites/'.$inside->id)->assertInertia(fn (Assert $page) => $page->where('modules.active', ['ext-active']));
        $this->get('/sites/'.$outside->id)->assertInertia(fn (Assert $page) => $page->where('modules.active', []));
        $this->get('/sites')->assertInertia(fn (Assert $page) => $page->where('modules.active', ['ext-active']));
    }

    public function test_module_data_is_withheld_on_sites_where_the_module_is_not_active(): void
    {
        $this->install('ext-withheld', 'ExtWithheld', "\$this->shareData(fn () => ['on' => true]);");
        $this->enable('ext-withheld');
        [$user, $inside, $outside] = $this->memberWithSites();
        app(ModuleScope::class)->limitTo('ext-withheld', [$inside->id]);

        $this->actingAs($user)->get('/sites/'.$inside->id)->assertInertia(fn (Assert $page) => $page->where('modules.data.ext-withheld', ['on' => true]));
        $this->get('/sites/'.$outside->id)->assertInertia(fn (Assert $page) => $page->where('modules.data', []));
    }

    public function test_a_site_limited_branding_applies_only_on_its_sites(): void
    {
        $this->install('ext-sitebrand', 'ExtSitebrand', "\$this->brand(fn () => ['name' => 'Client brand']);");
        $this->enable('ext-sitebrand');
        [$user, $inside, $outside] = $this->memberWithSites();
        app(ModuleScope::class)->limitTo('ext-sitebrand', [$inside->id]);

        $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('branding.name', 'Perysee'));
        $this->actingAs($user)->get('/sites/'.$inside->id)->assertInertia(fn (Assert $page) => $page->where('branding.name', 'Client brand'));
        $this->get('/sites/'.$outside->id)->assertInertia(fn (Assert $page) => $page->where('branding.name', 'Perysee'));
        $this->get('/sites')->assertInertia(fn (Assert $page) => $page->where('branding.name', 'Perysee'));

        app(ModuleScope::class)->allowAllSites('ext-sitebrand');
        $this->get('/sites')->assertInertia(fn (Assert $page) => $page->where('branding.name', 'Client brand'));
    }

    public function test_a_site_navigation_entry_links_to_the_current_site_and_only_where_the_module_is_active(): void
    {
        $this->install('ext-sitenav', 'ExtSitenav', <<<'BOOT'
        $this->loadModuleRoutes(__DIR__.'/../routes.php');
                $this->addNavigation('site', 'Notes', 'i-tabler-note', 'ext-sitenav.site');
        BOOT, "Route::get('/sites/{website}', fn (\\App\\Models\\Website \$website) => 'ok')->name('site');");
        $this->enable('ext-sitenav');
        [$user, $inside, $outside] = $this->memberWithSites();
        app(ModuleScope::class)->limitTo('ext-sitenav', [$inside->id]);

        $this->actingAs($user)->get('/sites/'.$inside->id)->assertInertia(fn (Assert $page) => $page
            ->where('modules.navigation.site', [['module' => 'ext-sitenav', 'label' => 'Notes', 'icon' => 'i-tabler-note', 'url' => '/modules/ext-sitenav/sites/'.$inside->id]]));
        $this->get('/sites/'.$outside->id)->assertInertia(fn (Assert $page) => $page->where('modules.navigation.site', []));
        $this->get('/sites')->assertInertia(fn (Assert $page) => $page->where('modules.navigation.site', []));
    }

    public function test_a_module_registers_scheduled_tasks_only_while_enabled(): void
    {
        $this->install('ext-sched', 'ExtSched', "\$this->schedule(fn (\\Illuminate\\Console\\Scheduling\\Schedule \$schedule) => \$schedule->call(fn () => null)->daily()->description('ext-sched tick'));");
        $descriptions = fn () => collect(app(Schedule::class)->events())->map(fn ($event) => $event->description)->all();

        $this->assertNotContains('ext-sched tick', $descriptions());
        $this->enable('ext-sched');

        $this->assertContains('ext-sched tick', $descriptions());
    }

    public function test_a_module_ships_its_own_artisan_commands(): void
    {
        $this->install('ext-cmd', 'ExtCmd', '$this->commands([\\Perysee\\Modules\\ExtCmd\\TickCommand::class]);', '', ['src/TickCommand.php' => <<<'PHP'
        <?php

        namespace Perysee\Modules\ExtCmd;

        use Illuminate\Console\Command;

        class TickCommand extends Command
        {
            protected $signature = 'ext-cmd:tick';

            protected $description = 'Ticks';

            public function handle(): int
            {
                $this->info('ticked');

                return self::SUCCESS;
            }
        }
        PHP]);
        $this->enable('ext-cmd');

        $this->artisan('ext-cmd:tick')->expectsOutputToContain('ticked')->assertSuccessful();
    }

    public function test_a_routes_file_with_a_syntax_error_isolates_the_module_without_breaking_the_core(): void
    {
        $this->install('ext-parse', 'ExtParse', "\$this->loadModuleRoutes(__DIR__.'/../routes.php');", 'this is not php');
        $this->enable('ext-parse');

        $this->assertArrayHasKey('ext-parse', app(ModuleLoader::class)->failures());
        $this->actingAs(User::factory()->create())->get('/sites')->assertOk();
    }

    public function test_a_module_page_cannot_replace_the_properties_the_application_shares(): void
    {
        $this->install('ext-reserved', 'ExtReserved', "\$this->loadModuleRoutes(__DIR__.'/../routes.php');", "Route::get('/', fn () => \\App\\Support\\Ui::render('module:greeter', ['links' => ['mine' => '/x']]))->name('index');");
        $this->enable('ext-reserved');

        $response = $this->actingAs(User::factory()->create())->withoutExceptionHandling();

        $this->expectExceptionMessage('"links" is shared by the application');
        $response->get('/modules/ext-reserved');
    }
}
