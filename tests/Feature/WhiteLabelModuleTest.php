<?php

namespace Tests\Feature;

use App\Installation\InstallationState;
use App\Models\User;
use App\Modules\ModuleLoader;
use App\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WhiteLabelModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
        Storage::fake('local');
        config(['perysee.modules_path' => base_path('modules')]);
        app()->forgetInstance(ModuleRegistry::class);
        app()->forgetInstance(ModuleLoader::class);
        app(ModuleRegistry::class)->enable('white-label');
        app(ModuleLoader::class)->load();
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function png(): UploadedFile
    {
        return UploadedFile::fake()->image('logo.png', 120, 40);
    }

    public function test_the_module_loads_without_errors(): void
    {
        $this->assertSame([], app(ModuleLoader::class)->failures());
    }

    public function test_without_settings_the_interface_keeps_the_perysee_identity(): void
    {
        $this->get('/login')->assertInertia(fn (Assert $page) => $page
            ->where('branding', ['name' => 'Perysee', 'logo' => null, 'accent' => null]));
    }

    public function test_an_administrator_customizes_the_name_and_accent_for_everyone_including_the_sign_in_page(): void
    {
        $this->actingAs($this->admin())->post('/modules/white-label/settings', ['name' => 'Acme Analytics', 'accent' => '#12ab34'])
            ->assertRedirect(route('modules.settings', 'white-label'))
            ->assertSessionHas('status');
        auth()->logout();

        $this->get('/login')->assertInertia(fn (Assert $page) => $page
            ->where('branding', ['name' => 'Acme Analytics', 'logo' => null, 'accent' => '#12ab34']));
    }

    public function test_invalid_values_are_rejected_and_nothing_is_saved(): void
    {
        $this->actingAs($this->admin());

        foreach ([
            ['name' => '<script>', 'accent' => '#12ab34'],
            ['name' => str_repeat('x', 41), 'accent' => '#12ab34'],
            ['name' => 'Acme', 'accent' => 'red'],
            ['name' => 'Acme', 'accent' => '#12ab34; background:url(x)'],
        ] as $payload) {
            $this->post('/modules/white-label/settings', $payload)->assertSessionHasErrors();
        }
        $this->assertDatabaseCount('module_settings', 0);
    }

    public function test_blank_fields_restore_the_defaults(): void
    {
        $this->actingAs($this->admin());
        $this->post('/modules/white-label/settings', ['name' => 'Acme', 'accent' => '#12ab34']);

        $this->post('/modules/white-label/settings', ['name' => '', 'accent' => ''])->assertSessionHasNoErrors();

        $this->get('/settings/modules/white-label')->assertInertia(fn (Assert $page) => $page->where('branding', ['name' => 'Perysee', 'logo' => null, 'accent' => null]));
    }

    public function test_only_administrators_can_change_anything(): void
    {
        $member = User::factory()->create();
        $this->post('/modules/white-label/settings', ['name' => 'Acme'])->assertRedirect('/login');
        $this->actingAs($member);

        $this->post('/modules/white-label/settings', ['name' => 'Acme'])->assertForbidden();
        $this->post('/modules/white-label/logo', ['logo' => $this->png()])->assertForbidden();
        $this->delete('/modules/white-label/logo')->assertForbidden();
        $this->delete('/modules/white-label/settings')->assertForbidden();
        $this->assertDatabaseCount('module_settings', 0);
    }

    public function test_an_uploaded_logo_is_served_publicly_and_used_as_the_brand_image(): void
    {
        $this->actingAs($this->admin())->post('/modules/white-label/logo', ['logo' => $this->png()])->assertRedirect(route('modules.settings', 'white-label'));
        auth()->logout();

        $this->get('/login')->assertInertia(fn (Assert $page) => $page
            ->where('branding.logo', fn (string $logo) => str_starts_with($logo, '/modules/white-label/public/logo?v=')));
        $response = $this->get('/modules/white-label/public/logo')->assertOk();
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('sandbox', $response->headers->get('Content-Security-Policy'));
    }

    public function test_files_that_are_not_images_are_refused(): void
    {
        $this->actingAs($this->admin());

        $this->post('/modules/white-label/logo', ['logo' => UploadedFile::fake()->create('logo.php', 1, 'application/x-php')])->assertSessionHasErrors('logo');
        $this->post('/modules/white-label/logo', ['logo' => UploadedFile::fake()->create('logo.png', 1, 'text/html')])->assertSessionHasErrors('logo');
        $this->post('/modules/white-label/logo', ['logo' => UploadedFile::fake()->image('big.png')->size(2000)])->assertSessionHasErrors('logo');
        $this->get('/modules/white-label/public/logo')->assertNotFound();
    }

    public function test_an_svg_logo_is_served_sandboxed(): void
    {
        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10"/></svg>');
        $this->actingAs($this->admin())->post('/modules/white-label/logo', ['logo' => $svg])->assertSessionHasNoErrors();

        $response = $this->get('/modules/white-label/public/logo')->assertOk();
        $this->assertSame('image/svg+xml', $response->headers->get('Content-Type'));
        $this->assertStringContainsString("default-src 'none'", $response->headers->get('Content-Security-Policy'));
    }

    public function test_replacing_and_removing_the_logo_leaves_no_file_behind(): void
    {
        $this->actingAs($this->admin());
        $this->post('/modules/white-label/logo', ['logo' => $this->png()]);
        $this->post('/modules/white-label/logo', ['logo' => UploadedFile::fake()->image('second.jpg')]);
        $this->assertCount(1, Storage::disk('local')->files('modules/white-label'));

        $this->delete('/modules/white-label/logo')->assertRedirect(route('modules.settings', 'white-label'));

        $this->assertSame([], Storage::disk('local')->files('modules/white-label'));
        $this->get('/modules/white-label/public/logo')->assertNotFound();
    }

    public function test_reset_removes_every_customization(): void
    {
        $this->actingAs($this->admin());
        $this->post('/modules/white-label/settings', ['name' => 'Acme', 'accent' => '#12ab34']);
        $this->post('/modules/white-label/logo', ['logo' => $this->png()]);

        $this->delete('/modules/white-label/settings')->assertRedirect(route('modules.settings', 'white-label'));

        $this->get('/settings/modules/white-label')->assertInertia(fn (Assert $page) => $page->where('branding', ['name' => 'Perysee', 'logo' => null, 'accent' => null]));
        $this->assertSame([], Storage::disk('local')->files('modules/white-label'));
    }

    public function test_the_settings_page_receives_the_form_data_for_administrators_only(): void
    {
        $this->actingAs($this->admin())->get('/settings/modules/white-label')->assertInertia(fn (Assert $page) => $page
            ->where('modules.data.white-label.defaults', ['name' => 'Perysee', 'accent' => '#5B21FF'])
            ->where('modules.data.white-label.links.save', '/modules/white-label/settings')
            ->where('modules.data.white-label.has_logo', false));
        $this->actingAs(User::factory()->create())->get('/sites')->assertInertia(fn (Assert $page) => $page
            ->where('modules.data.white-label', []));
    }

    public function test_it_ships_its_screen_assets_and_adds_no_navigation_entry(): void
    {
        $this->actingAs($this->admin())->get('/sites')->assertInertia(fn (Assert $page) => $page
            ->where('modules.scripts', [url('/modules/white-label/assets/module.js').'?v=1.0.0-'.substr(md5_file(base_path('modules/white-label/dist/module.js')), 0, 8)])
            ->where('modules.navigation', ['workspace' => [], 'admin' => [], 'site' => []]));
    }
}
