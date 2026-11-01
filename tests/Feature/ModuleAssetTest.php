<?php

namespace Tests\Feature;

use App\Installation\InstallationState;
use App\Models\User;
use App\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ModuleAssetTest extends TestCase
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
    }

    private function install(string $id, bool $enabled = true): void
    {
        File::ensureDirectoryExists($this->modulesPath.'/'.$id.'/dist');
        file_put_contents($this->modulesPath.'/'.$id.'/module.json', json_encode([
            'id' => $id, 'name' => $id, 'version' => '1.4.0', 'core' => '0.1.0',
        ]));
        file_put_contents($this->modulesPath.'/'.$id.'/dist/module.js', 'export default 1;');
        file_put_contents($this->modulesPath.'/'.$id.'/dist/module.css', '.a{color:red}');
        file_put_contents($this->modulesPath.'/'.$id.'/module.json.bak', 'secret');
        if ($enabled) {
            app(ModuleRegistry::class)->enable($id);
        }
    }

    public function test_an_enabled_module_serves_its_compiled_assets_with_the_right_types(): void
    {
        $this->install('assets-on');
        $this->actingAs(User::factory()->create());

        $script = $this->get('/modules/assets-on/assets/module.js')->assertOk();
        $this->assertStringStartsWith('text/javascript', $script->headers->get('Content-Type'));
        $this->assertSame('export default 1;', $script->streamedContent());
        $this->assertStringContainsString('immutable', $script->headers->get('Cache-Control'));
        $this->assertStringStartsWith('text/css', $this->get('/modules/assets-on/assets/module.css')->assertOk()->headers->get('Content-Type'));
    }

    public function test_assets_require_a_signed_in_user(): void
    {
        $this->install('assets-auth');

        $this->get('/modules/assets-auth/assets/module.js')->assertRedirect('/login');
    }

    public function test_disabled_or_unknown_modules_serve_nothing(): void
    {
        $this->install('assets-off', enabled: false);
        $this->actingAs(User::factory()->create());

        $this->get('/modules/assets-off/assets/module.js')->assertNotFound();
        $this->get('/modules/ghost/assets/module.js')->assertNotFound();
    }

    public function test_only_compiled_assets_are_reachable_and_paths_cannot_escape_dist(): void
    {
        $this->install('assets-safe');
        $this->actingAs(User::factory()->create());

        $this->get('/modules/assets-safe/assets/missing.js')->assertNotFound();
        $this->get('/modules/assets-safe/assets/module.json')->assertNotFound();
        $this->get('/modules/assets-safe/assets/..%2Fmodule.json')->assertNotFound();
        $this->get('/modules/assets-safe/assets/module.php')->assertNotFound();
    }

    public function test_shared_props_list_the_script_and_style_of_enabled_modules_with_a_version_query(): void
    {
        $this->install('assets-props');

        $this->actingAs(User::factory()->create())->get('/sites')->assertInertia(fn (Assert $page) => $page
            ->where('modules.scripts', [url('/modules/assets-props/assets/module.js').'?v=1.4.0-'.substr(md5('export default 1;'), 0, 8)])
            ->where('modules.styles', [url('/modules/assets-props/assets/module.css').'?v=1.4.0-'.substr(md5('.a{color:red}'), 0, 8)]));
    }

    public function test_the_file_address_changes_with_its_content_so_a_browser_never_keeps_a_stale_copy(): void
    {
        $this->install('assets-bust');
        $this->actingAs(User::factory()->create());
        $address = fn () => $this->get('/sites')->viewData('page')['props']['modules']['scripts'][0];
        $before = $address();

        file_put_contents($this->modulesPath.'/assets-bust/dist/module.js', 'export default 2;');

        $this->assertNotSame($before, $address());
        $this->assertStringContainsString('?v=1.4.0-', $address());
    }
}
