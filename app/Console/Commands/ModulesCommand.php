<?php

namespace App\Console\Commands;

use App\Modules\InvalidModuleBundle;
use App\Modules\ModuleInstaller;
use App\Modules\ModuleRegistry;
use Illuminate\Console\Command;
use RuntimeException;

class ModulesCommand extends Command
{
    protected $signature = 'perysee:modules {action : list, enable, disable, update or uninstall} {module? : Module id for enable, disable and uninstall} {--archive= : ZIP archive of the newer version, for update} {--accept-risk : Confirm that you accept enabling an experimental module} {--force : Confirm that uninstall deletes the tables and settings of the module}';

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
        $id = $this->argument('module');
        if (! in_array($action, ['enable', 'disable', 'update', 'uninstall'], true) || ! $id) {
            $this->error('Usage: perysee:modules list | enable <module> | disable <module> | update <module> --archive=<file.zip> | uninstall <module> --force');

            return self::FAILURE;
        }
        if ($action === 'uninstall' && ! $this->option('force')) {
            $this->error("Uninstalling {$id} permanently deletes its tables and settings. Run again with --force to confirm.");

            return self::FAILURE;
        }
        if ($action === 'update') {
            return $this->update($id);
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
