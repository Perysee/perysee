<?php

namespace Tests\Feature;

use App\Modules\ModuleRegistry;
use App\Modules\ModuleScope;
use App\Modules\ModuleSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ModuleInstallTest extends TestCase
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

    private function install(string $id, string $table, ?string $up = null): void
    {
        File::ensureDirectoryExists($this->modulesPath.'/'.$id.'/database/migrations');
        file_put_contents($this->modulesPath.'/'.$id.'/module.json', json_encode(['id' => $id, 'name' => $id, 'version' => '1.0.0', 'core' => '0.1.0']));
        $up ??= "Schema::create('{$table}', function (Blueprint \$table): void { \$table->id(); \$table->string('label'); });";
        file_put_contents($this->modulesPath.'/'.$id.'/database/migrations/2026_01_01_000000_create_'.$table.'.php', <<<PHP
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
        PHP);
    }

    public function test_a_module_does_not_touch_the_database_until_it_is_installed(): void
    {
        $this->install('mig-idle', 'mod_idle_things');

        app(ModuleRegistry::class)->all();

        $this->assertFalse(Schema::hasTable('mod_idle_things'));
        $this->assertFalse(app(ModuleRegistry::class)->all()['mig-idle']->installed);
    }

    public function test_enabling_a_module_for_the_first_time_runs_its_migrations(): void
    {
        $this->install('mig-first', 'mod_first_things');

        app(ModuleRegistry::class)->enable('mig-first');

        $this->assertTrue(Schema::hasTable('mod_first_things'));
        $this->assertTrue(app(ModuleRegistry::class)->all()['mig-first']->installed);
        $this->assertDatabaseHas('modules', ['id' => 'mig-first', 'enabled' => true]);
    }

    public function test_disabling_and_enabling_again_keeps_the_data_and_does_not_migrate_twice(): void
    {
        $this->install('mig-keep', 'mod_keep_things');
        $registry = app(ModuleRegistry::class);
        $registry->enable('mig-keep');
        DB::table('mod_keep_things')->insert(['label' => 'precious']);

        $registry->disable('mig-keep');
        $this->assertTrue(Schema::hasTable('mod_keep_things'));
        $registry->enable('mig-keep');

        $this->assertSame(['precious'], DB::table('mod_keep_things')->pluck('label')->all());
        $this->assertSame(1, DB::table('migrations')->where('migration', 'like', '%create_mod_keep_things')->count());
    }

    public function test_a_failing_migration_leaves_the_module_disabled_and_names_the_module(): void
    {
        $this->install('mig-bad', 'mod_bad_things', "throw new \\RuntimeException('disk full');");

        try {
            app(ModuleRegistry::class)->enable('mig-bad');
            $this->fail('Expected the installation to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('mig-bad', $exception->getMessage());
            $this->assertStringContainsString('disk full', $exception->getMessage());
        }

        $this->assertDatabaseMissing('modules', ['id' => 'mig-bad']);
        $this->assertFalse(app(ModuleRegistry::class)->all()['mig-bad']->enabled);
    }

    public function test_only_the_module_own_migrations_run_and_the_core_ones_are_left_alone(): void
    {
        $this->install('mig-scope', 'mod_scope_things');
        $before = DB::table('migrations')->count();

        app(ModuleRegistry::class)->enable('mig-scope');

        $this->assertSame($before + 1, DB::table('migrations')->count());
    }

    public function test_a_module_without_migrations_installs_without_touching_the_schema(): void
    {
        File::ensureDirectoryExists($this->modulesPath.'/mig-none');
        file_put_contents($this->modulesPath.'/mig-none/module.json', json_encode(['id' => 'mig-none', 'name' => 'None', 'version' => '1.0.0', 'core' => '0.1.0']));
        $before = DB::table('migrations')->count();

        app(ModuleRegistry::class)->enable('mig-none');

        $this->assertSame($before, DB::table('migrations')->count());
        $this->assertTrue(app(ModuleRegistry::class)->all()['mig-none']->installed);
    }

    public function test_uninstalling_removes_the_module_tables_records_and_settings_but_nothing_else(): void
    {
        $this->install('mig-gone', 'mod_gone_things');
        $this->install('mig-stay', 'mod_stay_things');
        $registry = app(ModuleRegistry::class);
        $registry->enable('mig-gone');
        $registry->enable('mig-stay');
        app(ModuleSettings::class)->set('mig-gone', 'k', 'v');
        app(ModuleSettings::class)->set('mig-stay', 'k', 'v');
        app(ModuleScope::class)->limitTo('mig-gone', []);
        $registry->disable('mig-gone');

        $registry->uninstall('mig-gone');

        $this->assertFalse(Schema::hasTable('mod_gone_things'));
        $this->assertTrue(Schema::hasTable('mod_stay_things'));
        $this->assertDatabaseMissing('modules', ['id' => 'mig-gone']);
        $this->assertDatabaseMissing('module_settings', ['module_id' => 'mig-gone']);
        $this->assertDatabaseMissing('module_scopes', ['module_id' => 'mig-gone']);
        $this->assertDatabaseHas('module_settings', ['module_id' => 'mig-stay']);
        $this->assertSame(0, DB::table('migrations')->where('migration', 'like', '%create_mod_gone_things')->count());
        $this->assertSame(1, DB::table('migrations')->where('migration', 'like', '%create_mod_stay_things')->count());
        $this->assertFalse(app(ModuleRegistry::class)->all()['mig-gone']->installed);
    }

    public function test_an_enabled_or_never_installed_module_cannot_be_uninstalled(): void
    {
        $this->install('mig-live', 'mod_live_things');
        $this->install('mig-never', 'mod_never_things');
        $registry = app(ModuleRegistry::class);
        $registry->enable('mig-live');

        try {
            $registry->uninstall('mig-live');
            $this->fail('An enabled module must be disabled first.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('disable', $exception->getMessage());
        }
        $this->assertTrue(Schema::hasTable('mod_live_things'));

        $this->expectExceptionMessage('is not installed');
        $registry->uninstall('mig-never');
    }

    public function test_a_module_can_be_installed_again_after_being_uninstalled(): void
    {
        $this->install('mig-again', 'mod_again_things');
        $registry = app(ModuleRegistry::class);
        $registry->enable('mig-again');
        $registry->disable('mig-again');
        $registry->uninstall('mig-again');

        $registry->enable('mig-again');

        $this->assertTrue(Schema::hasTable('mod_again_things'));
    }
}
