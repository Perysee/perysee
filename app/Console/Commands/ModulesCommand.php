<?php

namespace App\Console\Commands;

use App\Modules\ModuleRegistry;
use Illuminate\Console\Command;
use RuntimeException;

class ModulesCommand extends Command
{
    protected $signature = 'perysee:modules {action : list, enable, disable or uninstall} {module? : Module id for enable, disable and uninstall} {--force : Confirm that uninstall deletes the tables and settings of the module}';

    protected $description = 'List installed modules, or enable, disable or uninstall one';

    public function handle(ModuleRegistry $registry): int
    {
        $action = $this->argument('action');
        if ($action === 'list') {
            foreach ($registry->all() as $module) {
                $this->line("{$module->manifest->id} {$module->manifest->version} ".($module->enabled ? 'enabled' : 'disabled'));
            }
            foreach ($registry->invalid() as $directory => $reason) {
                $this->warn("Ignored {$directory}: {$reason}");
            }

            return self::SUCCESS;
        }
        $id = $this->argument('module');
        if (! in_array($action, ['enable', 'disable', 'uninstall'], true) || ! $id) {
            $this->error('Usage: perysee:modules list | enable <module> | disable <module> | uninstall <module> --force');

            return self::FAILURE;
        }
        if ($action === 'uninstall' && ! $this->option('force')) {
            $this->error("Uninstalling {$id} permanently deletes its tables and settings. Run again with --force to confirm.");

            return self::FAILURE;
        }
        try {
            match ($action) {
                'enable' => $registry->enable($id),
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
}
