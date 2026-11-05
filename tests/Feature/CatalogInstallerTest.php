<?php

namespace Tests\Feature;

use App\Analytics\InstanceHealth;
use App\Modules\Catalog\CatalogEntry;
use App\Modules\Catalog\CatalogException;
use App\Modules\Catalog\CatalogInstaller;
use App\Modules\Catalog\CatalogSignature;
use App\Modules\Catalog\ModuleCatalog;
use App\Modules\ModuleInstaller;
use App\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use ZipArchive;

class CatalogInstallerTest extends TestCase
{
    use RefreshDatabase;

    private const CATALOG = 'https://api.perysee.test/api/v1/catalog/modules';

    private string $secret;

    private string $modulesPath;

    protected function setUp(): void
    {
        parent::setUp();
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

    /**
     * A module archive in memory.
     *
     * @param  array<string, string>  $extra
     */
    private function archive(string $id = 'example', string $version = '1.0.0', array $extra = [], array $manifest = []): string
    {
        $path = $this->installationDirectory.'/'.bin2hex(random_bytes(4)).'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('module.json', json_encode($manifest + ['id' => $id, 'name' => ucfirst($id), 'version' => $version, 'core' => '0.1.0', 'api' => 1]));
        foreach ($extra as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();
        $bytes = file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    /**
     * Publishes $bytes as the download of a signed catalog entry and fakes both addresses.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function publish(string $bytes, array $overrides = [], ?string $served = null): string
    {
        $slug = $overrides['slug'] ?? 'example';
        $url = "https://github.com/Perysee/app/releases/download/v0.2.0/{$slug}.zip";
        $entry = $overrides + [
            'slug' => $slug, 'name' => ucfirst($slug), 'description' => 'A module.', 'version' => '1.0.0', 'requiredPlan' => 'free',
            'stability' => 'beta', 'tier' => 'official', 'api' => 1, 'core' => '0.1.0', 'downloadUrl' => $url, 'sha256' => hash('sha256', $bytes), 'keyId' => 'k1',
        ];
        $entry['signature'] = base64_encode(sodium_crypto_sign_detached(CatalogEntry::canonicalPayload($entry), $this->secret));
        Http::swap(new HttpFactory);
        Http::fake([self::CATALOG => Http::response(['data' => [$entry]]), $entry['downloadUrl'] => Http::response($served ?? $bytes)]);
        $this->app->make(ModuleCatalog::class)->refresh();

        return $slug;
    }

    private function installer(): CatalogInstaller
    {
        return $this->app->make(CatalogInstaller::class);
    }

    private function nothingWritten(): void
    {
        $this->assertSame([], array_values(array_diff(scandir($this->modulesPath), ['.', '..'])));
    }

    public function test_a_signed_free_module_is_downloaded_verified_and_installed_disabled(): void
    {
        $bytes = $this->archive(extra: ['src/Marker.php' => '<?php // ok']);
        $slug = $this->publish($bytes);

        $manifest = $this->installer()->install($slug);

        $this->assertSame('1.0.0', $manifest->version);
        $this->assertFileExists($this->modulesPath.'/example/src/Marker.php');
        $module = $this->app->make(ModuleRegistry::class)->all()['example'];
        $this->assertFalse($module->enabled);
        $source = $this->app->make(ModuleCatalog::class)->sources()['example'];
        $this->assertSame(['tier' => 'official', 'version' => '1.0.0', 'sha256' => hash('sha256', $bytes)], array_intersect_key($source, array_flip(['tier', 'version', 'sha256'])));
    }

    public function test_an_archive_that_does_not_match_the_signed_checksum_is_refused_and_nothing_is_written(): void
    {
        $slug = $this->publish($this->archive(), served: $this->archive(extra: ['src/Evil.php' => '<?php // swapped']));

        try {
            $this->installer()->install($slug);
            $this->fail('A swapped archive must be refused.');
        } catch (CatalogException $exception) {
            $this->assertStringContainsString('checksum', $exception->getMessage());
        }

        $this->nothingWritten();
        $this->assertArrayNotHasKey('example', $this->app->make(ModuleCatalog::class)->sources());
    }

    public function test_an_entry_with_a_bad_signature_is_never_downloaded(): void
    {
        $bytes = $this->archive();
        $slug = $this->publish($bytes);
        $stored = $this->app->make(ModuleCatalog::class)->stored();
        $stored['entries'][0]['sha256'] = str_repeat('0', 64);
        app(InstanceHealth::class)->put(ModuleCatalog::STATE, $stored);

        try {
            $this->installer()->install($slug);
            $this->fail('A tampered entry must be refused.');
        } catch (CatalogException $exception) {
            $this->assertStringContainsString('signature', $exception->getMessage());
        }

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'github.com'));
        $this->nothingWritten();
    }

    public function test_a_paid_module_is_listed_but_cannot_be_installed_from_the_catalog(): void
    {
        $slug = $this->publish($this->archive('annotations'), ['slug' => 'annotations', 'requiredPlan' => 'pro']);

        try {
            $this->installer()->install($slug);
            $this->fail('A paid module is not distributed here.');
        } catch (CatalogException $exception) {
            $this->assertStringContainsString('subscription', $exception->getMessage());
        }

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'github.com'));
        $this->nothingWritten();
    }

    public function test_a_module_for_another_module_api_or_a_newer_core_is_refused_with_the_reason(): void
    {
        $other = $this->publish($this->archive('future'), ['slug' => 'future', 'api' => 2]);
        try {
            $this->installer()->install($other);
            $this->fail('Another module API must be refused.');
        } catch (CatalogException $exception) {
            $this->assertStringContainsString('module API 2', $exception->getMessage());
        }

        $newer = $this->publish($this->archive('recent'), ['slug' => 'recent', 'core' => '9.0.0']);
        try {
            $this->installer()->install($newer);
            $this->fail('A newer Core is required.');
        } catch (CatalogException $exception) {
            $this->assertStringContainsString('9.0.0', $exception->getMessage());
        }
        $this->nothingWritten();
    }

    public function test_an_archive_whose_manifest_disagrees_with_the_signed_entry_is_refused_before_writing(): void
    {
        $slug = $this->publish($this->archive('example', '2.0.0'), ['version' => '1.0.0']);

        $this->expectException(CatalogException::class);
        $this->expectExceptionMessage('does not match');
        try {
            $this->installer()->install($slug);
        } finally {
            $this->nothingWritten();
        }
    }

    public function test_an_oversized_download_is_refused(): void
    {
        config(['perysee.module_upload_max_kb' => 1]);
        $slug = $this->publish($this->archive(extra: ['dist/module.js' => bin2hex(random_bytes(2048))]));

        $this->expectException(CatalogException::class);
        $this->expectExceptionMessage('too large');
        $this->installer()->install($slug);
    }

    public function test_a_newer_catalog_version_updates_a_disabled_installed_module_and_keeps_a_backup(): void
    {
        $this->publish($this->archive(extra: ['src/Marker.php' => '<?php // v1']));
        $this->installer()->install('example');
        $this->publish($this->archive('example', '1.1.0', ['src/Marker.php' => '<?php // v2']), ['version' => '1.1.0']);

        $manifest = $this->installer()->install('example');

        $this->assertSame('1.1.0', $manifest->version);
        $this->assertStringContainsString('v2', file_get_contents($this->modulesPath.'/example/src/Marker.php'));
        $this->assertCount(1, glob($this->installationDirectory.'/modules-backup/example-1.0.0-*'));
        $this->assertSame('1.1.0', $this->app->make(ModuleCatalog::class)->sources()['example']['version']);
    }

    public function test_the_command_installs_from_the_catalog_and_reports_a_refusal(): void
    {
        $slug = $this->publish($this->archive());

        $this->artisan('perysee:modules', ['action' => 'install-from-catalog', 'module' => $slug])->expectsOutputToContain('example 1.0.0')->assertSuccessful();
        $this->artisan('perysee:modules', ['action' => 'install-from-catalog', 'module' => 'ghost'])->expectsOutputToContain('not in the catalog')->assertFailed();
    }
}
