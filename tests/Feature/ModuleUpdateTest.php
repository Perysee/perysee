<?php

namespace Tests\Feature;

use App\Installation\InstallationState;
use App\Models\User;
use App\Modules\InvalidModuleBundle;
use App\Modules\ModuleInstaller;
use App\Modules\ModuleLoader;
use App\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use ZipArchive;

class ModuleUpdateTest extends TestCase
{
    use RefreshDatabase;

    private string $modulesPath;

    private string $backupsPath;

    protected function setUp(): void
    {
        parent::setUp();
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
        $this->modulesPath = $this->installationDirectory.'/modules';
        $this->backupsPath = $this->installationDirectory.'/modules-backup';
        mkdir($this->modulesPath);
        config(['perysee.modules_path' => $this->modulesPath, 'perysee.modules_backup_path' => $this->backupsPath, 'perysee.version' => '0.1.1', 'perysee.module_uploads' => true]);
        app()->forgetInstance(ModuleRegistry::class);
        app()->forgetInstance(ModuleLoader::class);
        app()->forgetInstance(ModuleInstaller::class);
    }

    private function migration(string $table, string $stamp, string $up = ''): string
    {
        $up = $up !== '' ? $up : "Schema::create('{$table}', function (Blueprint \$table): void { \$table->id(); \$table->string('label'); });";

        return <<<PHP
        <?php

        use Illuminate\\Database\\Migrations\\Migration;
        use Illuminate\\Database\\Schema\\Blueprint;
        use Illuminate\\Support\\Facades\\Schema;

        return new class extends Migration
        {
            public function up(): void
            {
                {$up}
            }

            public function down(): void
            {
                Schema::dropIfExists('{$table}');
            }
        };
        PHP;
    }

    /**
     * @param  array<string, string>  $files
     */
    private function archive(array $files): string
    {
        $path = $this->installationDirectory.'/'.bin2hex(random_bytes(4)).'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        foreach ($files as $entry => $contents) {
            $zip->addFromString($entry, $contents);
        }
        $zip->close();

        return $path;
    }

    private function manifest(string $version, array $overrides = []): string
    {
        return json_encode($overrides + ['id' => 'evolving', 'name' => 'Evolving', 'version' => $version, 'core' => '0.1.0']);
    }

    /**
     * Installs version 1.0.0 (one migration) from an archive and enables it once, as an administrator would.
     */
    private function installFirstVersion(bool $enabled = true): void
    {
        app(ModuleInstaller::class)->install($this->archive([
            'module.json' => $this->manifest('1.0.0'),
            'src/Marker.php' => '<?php // version one',
            'database/migrations/2026_01_01_000000_create_mod_evolving_a.php' => $this->migration('mod_evolving_a', '1'),
        ]));
        if ($enabled) {
            app(ModuleRegistry::class)->enable('evolving');
        }
    }

    private function secondVersion(array $extra = []): string
    {
        return $this->archive($extra + [
            'module.json' => $this->manifest('1.1.0'),
            'src/Marker.php' => '<?php // version two',
            'database/migrations/2026_01_01_000000_create_mod_evolving_a.php' => $this->migration('mod_evolving_a', '1'),
            'database/migrations/2026_02_01_000000_create_mod_evolving_b.php' => $this->migration('mod_evolving_b', '2'),
        ]);
    }

    private function registry(): ModuleRegistry
    {
        app(ModuleRegistry::class)->refresh();

        return app(ModuleRegistry::class);
    }

    public function test_a_newer_archive_replaces_the_files_and_keeps_the_old_version_in_a_backup(): void
    {
        $this->installFirstVersion();
        $this->registry()->disable('evolving');

        $manifest = app(ModuleInstaller::class)->update($this->secondVersion());

        $this->assertSame('1.1.0', $manifest->version);
        $this->assertStringContainsString('version two', file_get_contents($this->modulesPath.'/evolving/src/Marker.php'));
        $backups = glob($this->backupsPath.'/evolving-1.0.0-*');
        $this->assertCount(1, $backups);
        $this->assertStringContainsString('version one', file_get_contents($backups[0].'/src/Marker.php'));
        $this->assertSame('1.1.0', $this->registry()->all()['evolving']->manifest->version);
    }

    public function test_enabling_the_updated_module_runs_only_its_new_migrations_and_records_the_version(): void
    {
        $this->installFirstVersion();
        DB::table('mod_evolving_a')->insert(['label' => 'precious']);
        $this->registry()->disable('evolving');
        app(ModuleInstaller::class)->update($this->secondVersion());
        $this->assertFalse(Schema::hasTable('mod_evolving_b'), 'Replacing files must not touch the database.');

        $this->registry()->enable('evolving');

        $this->assertTrue(Schema::hasTable('mod_evolving_b'));
        $this->assertSame(['precious'], DB::table('mod_evolving_a')->pluck('label')->all());
        $this->assertDatabaseHas('modules', ['id' => 'evolving', 'version' => '1.1.0', 'enabled' => true]);
    }

    public function test_a_failing_new_migration_leaves_the_module_disabled_with_its_data_and_a_way_back(): void
    {
        $this->installFirstVersion();
        $this->registry()->disable('evolving');
        app(ModuleInstaller::class)->update($this->secondVersion([
            'database/migrations/2026_02_01_000000_create_mod_evolving_b.php' => $this->migration('mod_evolving_b', '2', "throw new \\RuntimeException('disk full');"),
        ]));

        try {
            $this->registry()->enable('evolving');
            $this->fail('The migration was expected to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('evolving', $exception->getMessage());
        }

        $this->assertFalse($this->registry()->all()['evolving']->enabled);
        $this->assertTrue(Schema::hasTable('mod_evolving_a'));
        $this->assertCount(1, glob($this->backupsPath.'/evolving-1.0.0-*'));
    }

    public function test_only_a_strictly_newer_version_is_accepted(): void
    {
        $this->installFirstVersion();
        $this->registry()->disable('evolving');

        foreach (['1.0.0', '0.9.0'] as $version) {
            try {
                app(ModuleInstaller::class)->update($this->archive(['module.json' => $this->manifest($version)]));
                $this->fail("Version {$version} must be refused.");
            } catch (InvalidModuleBundle $exception) {
                $this->assertStringContainsString('newer', $exception->getMessage());
            }
        }

        $this->assertSame('1.0.0', $this->registry()->all()['evolving']->manifest->version);
        $this->assertSame([], glob($this->backupsPath.'/*') ?: []);
    }

    public function test_an_enabled_module_must_be_disabled_before_it_is_replaced(): void
    {
        $this->installFirstVersion();

        try {
            app(ModuleInstaller::class)->update($this->secondVersion());
            $this->fail('An enabled module must not be replaced while it runs.');
        } catch (InvalidModuleBundle $exception) {
            $this->assertStringContainsString('Disable', $exception->getMessage());
        }

        $this->assertStringContainsString('version one', file_get_contents($this->modulesPath.'/evolving/src/Marker.php'));
    }

    public function test_an_archive_for_a_module_that_is_not_installed_is_refused_by_the_update(): void
    {
        $this->expectException(InvalidModuleBundle::class);
        $this->expectExceptionMessage('not installed');

        app(ModuleInstaller::class)->update($this->archive(['module.json' => $this->manifest('2.0.0', ['id' => 'stranger'])]));
    }

    public function test_an_unsafe_archive_leaves_the_installed_module_untouched(): void
    {
        $this->installFirstVersion();
        $this->registry()->disable('evolving');

        try {
            app(ModuleInstaller::class)->update($this->archive(['module.json' => $this->manifest('1.1.0'), 'run.sh' => 'rm -rf /']));
            $this->fail('A script must be refused.');
        } catch (InvalidModuleBundle) {
            // expected
        }

        $this->assertStringContainsString('version one', file_get_contents($this->modulesPath.'/evolving/src/Marker.php'));
        $this->assertSame([], glob($this->backupsPath.'/*') ?: []);
        $this->assertSame(['evolving'], array_values(array_diff(scandir($this->modulesPath), ['.', '..'])));
    }

    public function test_only_the_three_latest_backups_of_a_module_are_kept(): void
    {
        $this->installFirstVersion();
        foreach (['1.1.0', '1.2.0', '1.3.0', '1.4.0'] as $version) {
            $this->registry()->disable('evolving');
            app(ModuleInstaller::class)->update($this->archive(['module.json' => $this->manifest($version)]));
            $this->registry()->enable('evolving');
        }

        $this->assertCount(3, glob($this->backupsPath.'/evolving-*'));
        $this->assertCount(0, glob($this->backupsPath.'/evolving-1.0.0-*'));
    }

    public function test_an_archive_for_another_module_is_refused_before_anything_changes(): void
    {
        $this->installFirstVersion();
        $this->registry()->disable('evolving');
        $other = $this->archive(['module.json' => $this->manifest('2.0.0', ['id' => 'other', 'name' => 'Other'])]);

        try {
            app(ModuleInstaller::class)->update($other, 'evolving');
            $this->fail('An archive for another module must be refused.');
        } catch (InvalidModuleBundle $exception) {
            $this->assertStringContainsString('not evolving', $exception->getMessage());
        }

        $this->assertSame('1.0.0', $this->registry()->all()['evolving']->manifest->version);
        $this->assertSame([], glob($this->backupsPath.'/*') ?: []);
    }

    public function test_an_administrator_updates_a_module_from_the_modules_page(): void
    {
        $this->installFirstVersion();
        $this->registry()->disable('evolving');
        $admin = User::factory()->create(['is_admin' => true]);
        $file = new UploadedFile($this->secondVersion(), 'evolving.zip', 'application/zip', null, true);

        $this->actingAs($admin)->post('/settings/modules/evolving/update', ['bundle' => $file])
            ->assertRedirect(route('modules.index'))
            ->assertSessionHas('status', fn (string $message) => str_contains($message, '1.1.0'));

        $this->assertSame('1.1.0', $this->registry()->all()['evolving']->manifest->version);
        $this->actingAs(User::factory()->create())->post('/settings/modules/evolving/update', ['bundle' => $file])->assertForbidden();
    }

    public function test_the_command_updates_a_module_from_an_archive(): void
    {
        $this->installFirstVersion();
        $this->registry()->disable('evolving');

        $this->artisan('perysee:modules', ['action' => 'update', 'module' => 'evolving', '--archive' => $this->secondVersion()])
            ->expectsOutputToContain('1.1.0')
            ->assertSuccessful();

        $this->artisan('perysee:modules', ['action' => 'update', 'module' => 'evolving'])->expectsOutputToContain('--archive')->assertFailed();
    }
}
