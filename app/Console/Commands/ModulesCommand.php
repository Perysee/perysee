<?php

namespace App\Console\Commands;

use App\Modules\Catalog\CatalogException;
use App\Modules\Catalog\CatalogInstaller;
use App\Modules\Catalog\ModuleCatalog;
use App\Modules\InvalidModuleBundle;
use App\Modules\ModuleInstaller;
use App\Modules\ModuleRegistry;
use Illuminate\Console\Command;
use RuntimeException;

class ModulesCommand extends Command
{
    protected $signature = 'perysee:modules {action : list, catalog, install-from-catalog, enable, disable, update or uninstall} {module? : Module id for enable, disable and uninstall} {--refresh-only : With catalog, check the catalog and print only a failure} {--archive= : ZIP archive of the newer version, for update} {--accept-risk : Confirm that you accept enabling an experimental module} {--force : Confirm that uninstall deletes the tables and settings of the module}';

    protected $description = 'List installed modules, or enable, disable or uninstall one';

    public function handle(ModuleRegistry $registry): int
    {
        $action = $this->argument('action');
        if ($action === 'list') {
            foreach ($registry->all() as $module) {
                $this->line("{$module->manifest->id} {$module->manifest->version} ".($module->enabled ? 'enabled' : 'disabled')." {$module->manifest->stability}");
            }
            foreach ($registry->invalid() as $directory => $reason) {
                $this->warn("Ignored {$directory}: {$reason}");
            }

            return self::SUCCESS;
        }
        if ($action === 'catalog') {
            return $this->catalog();
        }
        $id = $this->argument('module');
        if (! in_array($action, ['enable', 'disable', 'update', 'uninstall', 'install-from-catalog'], true) || ! $id) {
            $this->error('Usage: perysee:modules list | catalog | install-from-catalog <module> | enable <module> | disable <module> | update <module> --archive=<file.zip> | uninstall <module> --force');

            return self::FAILURE;
        }
        if ($action === 'uninstall' && ! $this->option('force')) {
            $this->error("Uninstalling {$id} permanently deletes its tables and settings. Run again with --force to confirm.");

            return self::FAILURE;
        }
        if ($action === 'update') {
            return $this->update($id);
        }
        if ($action === 'install-from-catalog') {
            return $this->installFromCatalog($id);
        }
        try {
            match ($action) {
                'enable' => $registry->enable($id, (bool) $this->option('accept-risk')),
                'disable' => $registry->disable($id),
                'uninstall' => $registry->uninstall($id),
            };
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->info("Module {$id} ".($action === 'uninstall' ? 'uninstalled' : $action.'d').'.');

        return self::SUCCESS;
    }

    private function installFromCatalog(string $id): int
    {
        try {
            $manifest = app(CatalogInstaller::class)->install($id);
        } catch (CatalogException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->info("Module {$manifest->id} {$manifest->version} installed from the catalog. Enable it to start using it.");

        return self::SUCCESS;
    }

    private function catalog(): int
    {
        $catalog = app(ModuleCatalog::class);
        if (! $catalog->enabled()) {
            $this->warn('The module catalog is turned off (PERYSEE_MODULE_CATALOG=false).');

            return self::SUCCESS;
        }
        $state = $catalog->refresh();
        if ($state['error'] !== null) {
            $this->error($state['error']);

            return self::FAILURE;
        }
        if ($this->option('refresh-only')) {
            return self::SUCCESS;
        }
        foreach ($catalog->entries() as $entry) {
            $this->line("{$entry->slug} {$entry->version} {$entry->tier} {$entry->stability} ".($entry->isDownloadable() ? 'available' : 'not downloadable'));
        }

        return self::SUCCESS;
    }

    private function update(string $id): int
    {
        $archive = (string) $this->option('archive');
        if ($archive === '' || ! is_file($archive)) {
            $this->error('Give the ZIP archive of the newer version with --archive=<file.zip>.');

            return self::FAILURE;
        }
        try {
            $manifest = app(ModuleInstaller::class)->update($archive, $id);
        } catch (InvalidModuleBundle $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->info("Module {$id} updated to {$manifest->version}. Enable it to apply its database changes.");

        return self::SUCCESS;
    }
}
