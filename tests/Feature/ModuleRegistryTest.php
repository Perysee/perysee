<?php

namespace Tests\Feature;

use App\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ModuleRegistryTest extends TestCase
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
    }

    private function makeModule(string $id, array $overrides = []): void
    {
        File::ensureDirectoryExists($this->modulesPath.'/'.$id);
        file_put_contents($this->modulesPath.'/'.$id.'/module.json', json_encode($overrides + [
            'id' => $id, 'name' => ucfirst($id), 'version' => '1.0.0', 'core' => '0.1.0',
        ]));
    }

    private function registry(): ModuleRegistry
    {
        return app(ModuleRegistry::class);
    }

    public function test_installed_modules_are_discovered_and_disabled_by_default(): void
    {
        $this->makeModule('pdf-export');

        $modules = $this->registry()->all();

        $this->assertSame(['pdf-export'], array_keys($modules));
        $this->assertFalse($modules['pdf-export']->enabled);
        $this->assertSame([], $this->registry()->enabled());
    }

    public function test_a_directory_without_a_valid_manifest_is_reported_not_fatal(): void
    {
        $this->makeModule('good');
        mkdir($this->modulesPath.'/broken');
        file_put_contents($this->modulesPath.'/broken/module.json', '{nope');
        $this->makeModule('other', ['id' => 'mismatch']);

        $this->assertSame(['good'], array_keys($this->registry()->all()));
        $this->assertEqualsCanonicalizing(['broken', 'other'], array_keys($this->registry()->invalid()));
    }

    public function test_a_module_written_for_an_unsupported_api_is_refused_with_its_reason(): void
    {
        $this->makeModule('future-api', ['api' => 2]);

        try {
            $this->registry()->enable('future-api');
            $this->fail('A module for another module API must not be enabled.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('module API 2', $exception->getMessage());
        }

        $this->assertDatabaseMissing('modules', ['id' => 'future-api']);
    }

    public function test_an_experimental_module_is_not_enabled_without_an_explicit_acceptance(): void
    {
        $this->makeModule('lab', ['stability' => 'experimental']);

        try {
            $this->registry()->enable('lab');
            $this->fail('An experimental module must not be enabled without acceptance.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('experimental', $exception->getMessage());
        }

        $this->assertDatabaseMissing('modules', ['id' => 'lab']);
        $this->assertFalse($this->registry()->all()['lab']->enabled);
    }

    public function test_an_experimental_module_is_enabled_once_the_risk_is_accepted(): void
    {
        $this->makeModule('lab', ['stability' => 'experimental']);

        $this->registry()->enable('lab', acceptRisk: true);

        $this->assertDatabaseHas('modules', ['id' => 'lab', 'enabled' => true]);
    }

    public function test_stable_beta_and_undeclared_modules_need_no_acceptance(): void
    {
        $this->makeModule('solid', ['stability' => 'stable']);
        $this->makeModule('early', ['stability' => 'beta']);
        $this->makeModule('unlabelled');

        foreach (['solid', 'early', 'unlabelled'] as $id) {
            $this->registry()->enable($id);
            $this->assertDatabaseHas('modules', ['id' => $id, 'enabled' => true]);
        }
    }

    public function test_enabling_persists_the_state_and_installed_version(): void
    {
        $this->makeModule('pdf-export');

        $this->registry()->enable('pdf-export');

        $this->assertTrue($this->registry()->all()['pdf-export']->enabled);
        $this->assertDatabaseHas('modules', ['id' => 'pdf-export', 'version' => '1.0.0', 'enabled' => true]);
        $this->assertSame(['pdf-export'], array_keys($this->registry()->enabled()));
    }

    public function test_a_module_requiring_a_newer_core_cannot_be_enabled(): void
    {
        $this->makeModule('future', ['core' => '9.0.0']);

        $this->expectExceptionMessage('requires Perysee 9.0.0');
        $this->registry()->enable('future');
    }

    public function test_enabling_requires_its_dependencies_to_be_enabled(): void
    {
        $this->makeModule('base');
        $this->makeModule('addon', ['requires' => ['base']]);

        try {
            $this->registry()->enable('addon');
            $this->fail('Expected a missing dependency error.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('base', $exception->getMessage());
        }

        $this->registry()->enable('base');
        $this->registry()->enable('addon');
        $this->assertSame(['base', 'addon'], array_keys($this->registry()->enabled()));
    }

    public function test_a_module_required_by_an_enabled_one_cannot_be_disabled(): void
    {
        $this->makeModule('base');
        $this->makeModule('addon', ['requires' => ['base']]);
        $this->registry()->enable('base');
        $this->registry()->enable('addon');

        try {
            $this->registry()->disable('base');
            $this->fail('Expected a dependent module error.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('addon', $exception->getMessage());
        }

        $this->registry()->disable('addon');
        $this->registry()->disable('base');
        $this->assertSame([], $this->registry()->enabled());
    }

    public function test_unknown_modules_are_rejected(): void
    {
        $this->expectExceptionMessage('is not installed');
        $this->registry()->enable('ghost');
    }
}
