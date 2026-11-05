<?php

namespace Tests\Feature;

use App\Analytics\InstanceHealth;
use App\Installation\InstallationState;
use App\Models\User;
use App\Modules\Catalog\CatalogEntry;
use App\Modules\Catalog\CatalogInstaller;
use App\Modules\Catalog\CatalogSignature;
use App\Modules\Catalog\ModuleCatalog;
use App\Modules\ModuleInstaller;
use App\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use ZipArchive;

class ModuleCatalogPageTest extends TestCase
{
    use RefreshDatabase;

    private const CATALOG = 'https://api.perysee.test/api/v1/catalog/modules';

    private string $secret;

    private string $modulesPath;

    protected function setUp(): void
    {
        parent::setUp();
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
        $pair = sodium_crypto_sign_keypair();
        $this->secret = sodium_crypto_sign_secretkey($pair);
        $this->modulesPath = $this->installationDirectory.'/modules';
        mkdir($this->modulesPath);
        config([
            'perysee.modules_path' => $this->modulesPath,
            'perysee.modules_backup_path' => $this->installationDirectory.'/modules-backup',
            'perysee.version' => '0.1.1',
            'perysee.module_uploads' => true,
            'perysee.module_catalog' => ['enabled' => true, 'url' => self::CATALOG, 'keys_path' => ''],
        ]);
        $this->app->instance(CatalogSignature::class, new CatalogSignature(['k1' => base64_encode(sodium_crypto_sign_publickey($pair))]));
        foreach ([ModuleRegistry::class, ModuleCatalog::class, ModuleInstaller::class, CatalogInstaller::class] as $class) {
            $this->app->forgetInstance($class);
        }
    }

    private function bytes(string $id, string $version): string
    {
        $path = $this->installationDirectory.'/'.bin2hex(random_bytes(4)).'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('module.json', json_encode(['id' => $id, 'name' => ucfirst($id), 'version' => $version, 'core' => '0.1.0', 'api' => 1]));
        $zip->close();
        $bytes = file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    /**
     * @param  list<array{slug: string, version?: string, overrides?: array<string, mixed>}>  $modules
     */
    private function publish(array $modules): void
    {
        Http::swap(new HttpFactory);
        $entries = [];
        $responses = [];
        foreach ($modules as $module) {
            $slug = $module['slug'];
            $version = $module['version'] ?? '1.0.0';
            $bytes = $this->bytes($slug, $version);
            $entry = ($module['overrides'] ?? []) + [
                'slug' => $slug, 'name' => ucfirst($slug), 'description' => "The {$slug} module.", 'version' => $version, 'requiredPlan' => 'free',
                'stability' => 'beta', 'tier' => 'official', 'api' => 1, 'core' => '0.1.0',
                'downloadUrl' => "https://github.com/Perysee/app/releases/download/v1/{$slug}-{$version}.zip", 'sha256' => hash('sha256', $bytes), 'keyId' => 'k1',
            ];
            $entry['signature'] = base64_encode(sodium_crypto_sign_detached(CatalogEntry::canonicalPayload($entry), $this->secret));
            $entries[] = $entry;
            $responses[$entry['downloadUrl']] = Http::response($bytes);
        }
        Http::fake([self::CATALOG => Http::response(['data' => $entries])] + $responses);
        $this->app->make(ModuleCatalog::class)->refresh();
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_the_page_lists_what_the_catalog_offers_and_why_something_cannot_be_installed(): void
    {
        $this->publish([
            ['slug' => 'alpha'],
            ['slug' => 'beta-pro', 'overrides' => ['requiredPlan' => 'pro']],
            ['slug' => 'gamma', 'overrides' => ['api' => 2]],
            ['slug' => 'delta', 'overrides' => ['core' => '9.0.0']],
        ]);

        $this->actingAs($this->admin())->get('/settings/modules')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('catalog.enabled', true)
            ->where('catalog.error', null)
            ->where('catalog.entries.0', fn ($entry) => $entry['slug'] === 'alpha' && $entry['state'] === 'available' && $entry['tier'] === 'official' && $entry['stability'] === 'beta' && $entry['reason'] === null)
            ->where('catalog.entries.1', fn ($entry) => $entry['state'] === 'unavailable' && str_contains($entry['reason'], 'subscription'))
            ->where('catalog.entries.2', fn ($entry) => $entry['state'] === 'unavailable' && str_contains($entry['reason'], 'module API 2'))
            ->where('catalog.entries.3', fn ($entry) => $entry['state'] === 'unavailable' && str_contains($entry['reason'], '9.0.0')));
    }

    public function test_an_installed_module_is_shown_as_installed_or_as_having_an_update(): void
    {
        $this->publish([['slug' => 'alpha'], ['slug' => 'omega']]);
        $this->app->make(CatalogInstaller::class)->install('alpha');
        $this->app->make(CatalogInstaller::class)->install('omega');
        $this->publish([['slug' => 'alpha'], ['slug' => 'omega', 'version' => '1.1.0']]);
        $this->app->forgetInstance(ModuleRegistry::class);

        $this->actingAs($this->admin())->get('/settings/modules')->assertInertia(fn (Assert $page) => $page
            ->where('catalog.entries.0', fn ($entry) => $entry['slug'] === 'alpha' && $entry['state'] === 'installed' && $entry['installed'] === '1.0.0')
            ->where('catalog.entries.1', fn ($entry) => $entry['slug'] === 'omega' && $entry['state'] === 'update' && $entry['installed'] === '1.0.0'));
    }

    public function test_an_administrator_installs_from_the_catalog_and_a_member_cannot(): void
    {
        $this->publish([['slug' => 'alpha']]);

        $this->actingAs(User::factory()->create())->post('/settings/modules/catalog/alpha/install')->assertForbidden();
        $this->actingAs($this->admin())->post('/settings/modules/catalog/alpha/install')
            ->assertRedirect(route('modules.index'))
            ->assertSessionHas('status', fn (string $message) => str_contains($message, 'Alpha 1.0.0') && str_contains($message, 'Enable'));

        $this->assertFileExists($this->modulesPath.'/alpha/module.json');
    }

    public function test_a_refused_install_shows_the_reason_and_installs_nothing(): void
    {
        $this->publish([['slug' => 'pricey', 'overrides' => ['requiredPlan' => 'agency']]]);

        $this->actingAs($this->admin())->post('/settings/modules/catalog/pricey/install')
            ->assertRedirect(route('modules.index'))
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'subscription'));

        $this->assertDirectoryDoesNotExist($this->modulesPath.'/pricey');
    }

    public function test_the_administrator_can_check_the_catalog_again(): void
    {
        $this->publish([['slug' => 'alpha']]);
        Http::swap(new HttpFactory);
        Http::fake([self::CATALOG => Http::response(['data' => []])]);

        $this->actingAs($this->admin())->post('/settings/modules/catalog/refresh')->assertRedirect(route('modules.index'))->assertSessionHas('status');

        $this->assertSame([], $this->app->make(ModuleCatalog::class)->entries());
        $this->actingAs(User::factory()->create())->post('/settings/modules/catalog/refresh')->assertForbidden();
    }

    public function test_a_failed_check_is_reported_and_the_old_list_stays(): void
    {
        $this->publish([['slug' => 'alpha']]);
        Http::swap(new HttpFactory);
        Http::fake([self::CATALOG => Http::response('down', 503)]);

        $this->actingAs($this->admin())->post('/settings/modules/catalog/refresh')->assertSessionHas('error');
        $this->actingAs($this->admin())->get('/settings/modules')->assertInertia(fn (Assert $page) => $page
            ->where('catalog.error', fn ($error) => str_contains($error, '503'))
            ->has('catalog.entries', 1));
    }

    public function test_a_turned_off_catalog_offers_nothing_and_makes_no_call(): void
    {
        config(['perysee.module_catalog.enabled' => false]);
        Http::fake();
        $admin = $this->admin();

        $this->actingAs($admin)->get('/settings/modules')->assertInertia(fn (Assert $page) => $page->where('catalog.enabled', false)->has('catalog.entries', 0));
        $this->actingAs($admin)->post('/settings/modules/catalog/refresh')->assertRedirect(route('modules.index'));

        Http::assertNothingSent();
        $this->assertSame([], $this->app->make(InstanceHealth::class)->get(ModuleCatalog::STATE, []));
    }
}
