<?php

declare(strict_types=1);

namespace App\Modules;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * @internal Not part of the module API: modules must not call it, and it may change in any release.
 */
final class ModuleRegistry
{
    /** @var array<string, Module>|null */
    private ?array $modules = null;

    /** @var array<string, string> */
    private array $invalid = [];

    /**
     * Installed modules with a valid manifest, keyed by id.
     *
     * @return array<string, Module>
     */
    public function all(): array
    {
        return $this->modules ??= $this->discover();
    }

    /**
     * Forgets what was read, so a module added to the folder during this request is seen.
     */
    public function refresh(): void
    {
        $this->modules = null;
    }

    /**
     * Enabled modules in dependency order.
     *
     * @return array<string, Module>
     */
    public function enabled(): array
    {
        $enabled = array_filter($this->all(), fn (Module $module): bool => $module->enabled);
        $ordered = [];
        $visit = function (string $id) use (&$visit, &$ordered, $enabled): void {
            if (isset($ordered[$id]) || ! isset($enabled[$id])) {
                return;
            }
            foreach ($enabled[$id]->manifest->requires as $dependency) {
                $visit($dependency);
            }
            $ordered[$id] = $enabled[$id];
        };
        foreach (array_keys($enabled) as $id) {
            $visit($id);
        }

        return $ordered;
    }

    /**
     * Rejected module directories mapped to the reason.
     *
     * @return array<string, string>
     */
    public function invalid(): array
    {
        $this->all();

        return $this->invalid;
    }

    /**
     * @param  bool  $acceptRisk  Confirms that the administrator knows an experimental module may break or lose data.
     */
    public function enable(string $id, bool $acceptRisk = false): void
    {
        $module = $this->installed($id);
        if ($module->manifest->stability === 'experimental' && ! $acceptRisk) {
            throw new RuntimeException("Module {$id} is experimental and may break or lose data. Back up first, then confirm that you accept the risk to enable it.");
        }
        if (! $module->manifest->isCompatibleWith(config('perysee.version'))) {
            throw new RuntimeException("Module {$id} requires Perysee {$module->manifest->core} or later.");
        }
        if (! $module->manifest->supportsCurrentApi()) {
            throw new RuntimeException("Module {$id} was written for module API {$module->manifest->api}, but this Perysee supports module API ".ModuleApi::VERSION.'.');
        }
        foreach ($module->manifest->requires as $dependency) {
            if (! ($this->all()[$dependency]->enabled ?? false)) {
                throw new RuntimeException("Module {$id} requires {$dependency} to be enabled first.");
            }
        }
        // Pending migrations run every time: the first enable installs the module, a later one applies those of an updated version.
        $this->migrate($module);
        $this->store($module, true);
        app(ModuleFailures::class)->release($id);
    }

    public function disable(string $id): void
    {
        $module = $this->installed($id);
        foreach ($this->enabled() as $other) {
            if (in_array($id, $other->manifest->requires, true)) {
                throw new RuntimeException("Module {$id} is required by {$other->manifest->id}. Disable it first.");
            }
        }
        $this->store($module, false);
    }

    /**
     * Removes an installed module for good: its tables (the `down()` of its migrations), its settings and its scope.
     * The module must be disabled first. Only this module's own migrations are rolled back.
     */
    public function uninstall(string $id): void
    {
        $module = $this->installed($id);
        if ($module->enabled) {
            throw new RuntimeException("Module {$id} is enabled; disable it before uninstalling.");
        }
        if (! $module->installed) {
            throw new RuntimeException("Module {$id} is not installed.");
        }
        $path = $module->path.'/database/migrations';
        try {
            if (glob($path.'/*.php')) {
                app('migrator')->reset([$path]);
            }
        } catch (Throwable $exception) {
            throw new RuntimeException("Module {$id} could not be uninstalled: {$exception->getMessage()}", 0, $exception);
        }
        DB::transaction(function () use ($id): void {
            foreach (['module_settings', 'module_sites', 'module_scopes'] as $table) {
                DB::table($table)->where('module_id', $id)->delete();
            }
            DB::table('modules')->where('id', $id)->delete();
        });
        $this->modules = null;
    }

    /**
     * Installing a module means running its own migrations, once, when it is first enabled.
     * Disabling keeps its tables and data. Migration file names must be unique across modules, so prefix them with the module id.
     */
    private function migrate(Module $module): void
    {
        $path = $module->path.'/database/migrations';
        if (! glob($path.'/*.php')) {
            return;
        }
        try {
            app('migrator')->run([$path]);
        } catch (Throwable $exception) {
            throw new RuntimeException("Module {$module->manifest->id} could not be installed: {$exception->getMessage()}", 0, $exception);
        }
    }

    private function installed(string $id): Module
    {
        return $this->all()[$id] ?? throw new RuntimeException("Module {$id} is not installed.");
    }

    private function store(Module $module, bool $enabled): void
    {
        $now = now();
        DB::table('modules')->upsert(
            [['id' => $module->manifest->id, 'version' => $module->manifest->version, 'enabled' => $enabled, 'installed_at' => $now, 'created_at' => $now, 'updated_at' => $now]],
            ['id'],
            ['version', 'enabled', 'updated_at'],
        );
        $this->modules = null;
    }

    /**
     * @return array<string, Module>
     */
    private function discover(): array
    {
        $this->invalid = [];
        $states = $this->states();
        $modules = [];
        foreach (glob(rtrim(config('perysee.modules_path'), '/').'/*', GLOB_ONLYDIR) ?: [] as $directory) {
            $directoryName = basename($directory);
            try {
                $manifest = Manifest::fromFile($directory.'/module.json');
                if ($manifest->id !== $directoryName) {
                    throw new InvalidManifest("Module id {$manifest->id} does not match its directory.");
                }
            } catch (InvalidManifest $exception) {
                $this->invalid[$directoryName] = $exception->getMessage();

                continue;
            }
            $modules[$manifest->id] = new Module($manifest, $directory, $states[$manifest->id] ?? false, isset($states[$manifest->id]));
        }
        ksort($modules);

        return $modules;
    }

    /**
     * Enabled flags by module id. Empty before the modules table exists, such as during installation.
     *
     * @return array<string, bool>
     */
    private function states(): array
    {
        try {
            return DB::table('modules')->pluck('enabled', 'id')->map(fn ($enabled): bool => (bool) $enabled)->all();
        } catch (QueryException) {
            return [];
        }
    }
}
