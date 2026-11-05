<?php

namespace Tests\Feature;

use App\Installation\InstallationState;
use App\Models\User;
use App\Modules\ModuleLoader;
use App\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use ZipArchive;

class ModuleUploadTest extends TestCase
{
    use RefreshDatabase;

    private string $modulesPath;

    protected function setUp(): void
    {
        parent::setUp();
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
        $this->modulesPath = $this->installationDirectory.'/modules';
        mkdir($this->modulesPath);
        config(['perysee.modules_path' => $this->modulesPath, 'perysee.version' => '0.1.1', 'perysee.module_uploads' => true]);
        app()->forgetInstance(ModuleRegistry::class);
        app()->forgetInstance(ModuleLoader::class);
    }

    /**
     * @param  array<string, string>  $files  path inside the archive => contents
     */
    private function bundle(array $files, string $name = 'bundle.zip'): UploadedFile
    {
        $path = $this->installationDirectory.'/'.bin2hex(random_bytes(4)).'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        foreach ($files as $entry => $contents) {
            $zip->addFromString($entry, $contents);
        }
        $zip->close();

        return new UploadedFile($path, $name, 'application/zip', null, true);
    }

    private function manifest(array $overrides = []): string
    {
        return json_encode($overrides + ['id' => 'uploaded', 'name' => 'Uploaded', 'version' => '1.0.0', 'core' => '0.1.0', 'description' => 'From a ZIP.']);
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function upload(UploadedFile $file)
    {
        return $this->post('/settings/modules/install', ['bundle' => $file]);
    }

    private function leftovers(): array
    {
        return array_values(array_diff(scandir($this->modulesPath), ['.', '..']));
    }

    public function test_an_administrator_installs_a_module_from_a_zip_without_running_any_of_its_code(): void
    {
        $this->actingAs($this->admin());
        $marker = $this->installationDirectory.'/ran';
        $file = $this->bundle([
            'module.json' => $this->manifest(),
            'src/Boom.php' => "<?php\nfile_put_contents('{$marker}', 'ran');\n",
            'routes.php' => "<?php\n",
            'dist/module.js' => '// ui',
        ]);

        $this->upload($file)->assertRedirect(route('modules.index'))->assertSessionHas('status', fn (string $message) => str_contains($message, 'Uploaded') && str_contains($message, 'Enable'));

        $this->assertFileExists($this->modulesPath.'/uploaded/module.json');
        $this->assertFileExists($this->modulesPath.'/uploaded/src/Boom.php');
        $this->assertFileDoesNotExist($marker);
        $module = app(ModuleRegistry::class)->all()['uploaded'];
        $this->assertFalse($module->enabled);
        $this->assertFalse($module->installed);
        $this->assertSame(['uploaded'], $this->leftovers());
    }

    public function test_an_archive_with_a_single_top_level_folder_is_accepted_and_system_files_are_ignored(): void
    {
        $this->actingAs($this->admin());

        $this->upload($this->bundle([
            'uploaded/module.json' => $this->manifest(),
            'uploaded/src/ModuleServiceProvider.php' => "<?php\n",
            'uploaded/.DS_Store' => 'junk',
            '__MACOSX/uploaded/._module.json' => 'junk',
        ]))->assertSessionHas('status');

        $this->assertFileExists($this->modulesPath.'/uploaded/src/ModuleServiceProvider.php');
        $this->assertFileDoesNotExist($this->modulesPath.'/uploaded/.DS_Store');
        $this->assertSame(['uploaded'], $this->leftovers());
    }

    public function test_the_installed_module_can_then_be_enabled_and_runs_its_migrations_then(): void
    {
        $this->actingAs($this->admin());
        $this->upload($this->bundle([
            'module.json' => $this->manifest(['id' => 'with-table']),
            'database/migrations/2026_01_01_000000_create_with_table_things.php' => "<?php\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Database\\Schema\\Blueprint;\nuse Illuminate\\Support\\Facades\\Schema;\n\nreturn new class extends Migration\n{\n    public function up(): void\n    {\n        Schema::create('with_table_things', function (Blueprint \$table): void { \$table->id(); });\n    }\n\n    public function down(): void\n    {\n        Schema::dropIfExists('with_table_things');\n    }\n};\n",
        ]));
        $this->assertFalse(Schema::hasTable('with_table_things'));

        $this->post('/settings/modules/with-table/enable')->assertSessionHas('status');

        $this->assertTrue(Schema::hasTable('with_table_things'));
    }

    public function test_only_administrators_can_install_and_visitors_are_sent_to_sign_in(): void
    {
        $file = $this->bundle(['module.json' => $this->manifest()]);

        $this->upload($file)->assertRedirect('/login');
        $this->actingAs(User::factory()->create());
        $this->upload($this->bundle(['module.json' => $this->manifest()]))->assertForbidden();
        $this->assertSame([], $this->leftovers());
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: string}>
     */
    public static function refusedBundles(): array
    {
        $ok = json_encode(['id' => 'uploaded', 'name' => 'Uploaded', 'version' => '1.0.0', 'core' => '0.1.0']);

        return [
            'no manifest' => [['src/a.php' => '<?php'], 'module.json'],
            'broken manifest' => [['module.json' => '{nope'], 'module.json'],
            'unsafe id' => [['module.json' => json_encode(['id' => '../evil', 'name' => 'X', 'version' => '1.0.0', 'core' => '0.1.0'])], 'id'],
            'path traversal' => [['module.json' => $ok, '../evil.php' => '<?php'], 'unsafe'],
            'nested traversal' => [['module.json' => $ok, 'src/../../evil.php' => '<?php'], 'unsafe'],
            'absolute path' => [['module.json' => $ok, '/etc/evil.php' => '<?php'], 'unsafe'],
            'backslash path' => [['module.json' => $ok, 'src\\..\\evil.php' => '<?php'], 'unsafe'],
            'executable script' => [['module.json' => $ok, 'run.sh' => '#!/bin/sh'], 'run.sh'],
            'alternative php extension' => [['module.json' => $ok, 'src/page.phtml' => '<?php'], 'page.phtml'],
            'two modules' => [['a/module.json' => $ok, 'b/module.json' => $ok], 'module.json'],
            'needs a newer core' => [['module.json' => json_encode(['id' => 'uploaded', 'name' => 'X', 'version' => '1.0.0', 'core' => '9.0.0'])], '9.0.0'],
        ];
    }

    #[DataProvider('refusedBundles')]
    public function test_unsafe_or_invalid_archives_are_refused_with_a_reason_and_leave_nothing_behind(array $files, string $mention): void
    {
        $this->actingAs($this->admin());

        $this->upload($this->bundle($files))->assertRedirect(route('modules.index'))
            ->assertSessionHas('error', fn (string $message) => str_contains(strtolower($message), strtolower($mention)));

        $this->assertSame([], $this->leftovers());
    }

    public function test_a_symbolic_link_inside_the_archive_is_refused(): void
    {
        $this->actingAs($this->admin());
        $path = $this->installationDirectory.'/link.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('module.json', $this->manifest());
        $zip->addFromString('link', '/etc/passwd');
        $zip->setExternalAttributesName('link', ZipArchive::OPSYS_UNIX, 0120777 << 16);
        $zip->close();

        $this->upload(new UploadedFile($path, 'link.zip', 'application/zip', null, true))
            ->assertSessionHas('error', fn (string $message) => str_contains(strtolower($message), 'link'));

        $this->assertSame([], $this->leftovers());
    }

    public function test_an_archive_for_an_unsupported_module_api_is_refused_and_leaves_nothing_behind(): void
    {
        $this->actingAs($this->admin());

        $this->upload($this->bundle(['module.json' => $this->manifest(['api' => 99])]))
            ->assertRedirect(route('modules.index'))
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'module API 99'));

        $this->assertSame([], $this->leftovers());
    }

    public function test_a_module_that_is_already_installed_is_never_overwritten(): void
    {
        $this->actingAs($this->admin());
        File::ensureDirectoryExists($this->modulesPath.'/uploaded');
        file_put_contents($this->modulesPath.'/uploaded/module.json', $this->manifest(['version' => '0.9.0']));

        $this->upload($this->bundle(['module.json' => $this->manifest(['version' => '2.0.0'])]))
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'already'));

        $this->assertSame('0.9.0', json_decode(file_get_contents($this->modulesPath.'/uploaded/module.json'), true)['version']);
        $this->assertSame(['uploaded'], $this->leftovers());
    }

    public function test_an_oversized_or_non_zip_upload_is_refused(): void
    {
        $this->actingAs($this->admin());
        config(['perysee.module_upload_max_kb' => 1]);

        $this->upload($this->bundle(['module.json' => $this->manifest(), 'dist/big.js' => bin2hex(random_bytes(2500))]))->assertSessionHasErrors('bundle');
        config(['perysee.module_upload_max_kb' => 20480]);
        $this->post('/settings/modules/install', ['bundle' => UploadedFile::fake()->createWithContent('module.zip', 'not a zip')])->assertSessionHas('error', fn (string $message) => str_contains($message, 'valid ZIP'));
        $this->post('/settings/modules/install', [])->assertSessionHasErrors('bundle');
        $this->assertSame([], $this->leftovers());
    }

    public function test_an_archive_that_expands_beyond_the_limit_is_refused_before_anything_is_written(): void
    {
        $this->actingAs($this->admin());
        $files = ['module.json' => $this->manifest()];
        for ($i = 0; $i < 2100; $i++) {
            $files["dist/f{$i}.js"] = '1';
        }

        $this->upload($this->bundle($files))->assertSessionHas('error', fn (string $message) => str_contains($message, 'files'));

        $this->assertSame([], $this->leftovers());
    }

    public function test_the_modules_page_tells_whether_installing_is_possible_and_why_not(): void
    {
        $this->actingAs($this->admin())->get('/settings/modules')->assertInertia(fn (Assert $page) => $page
            ->where('installer.available', true)
            ->where('installer.link', '/settings/modules/install')
            ->where('installer.maxMb', 20));

        config(['perysee.module_uploads' => false]);
        $this->get('/settings/modules')->assertInertia(fn (Assert $page) => $page
            ->where('installer.available', false)
            ->where('installer.reason', fn (string $reason) => str_contains($reason, 'PERYSEE_MODULE_UPLOADS')));
        $this->upload($this->bundle(['module.json' => $this->manifest()]))->assertForbidden();
        $this->assertSame([], $this->leftovers());
    }
}
