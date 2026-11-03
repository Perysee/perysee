<?php

namespace App\Console\Commands;

use App\Updates\Backup;
use App\Updates\CodeSwapper;
use App\Updates\UpdateException;
use App\Updates\UpdateStatus;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class RollbackUpdate extends Command
{
    protected $signature = 'perysee:update:rollback {--up : Reopen the instance afterwards (only once the database matches the restored version)} {--yes : Do not ask for confirmation}';

    protected $description = 'Put back the application files replaced by the last update';

    public function handle(Backup $backup, CodeSwapper $swapper, UpdateStatus $status): int
    {
        $folder = $backup->latestWithPreviousVersion();
        if ($folder === null) {
            $this->error('There is no previous version to restore.');

            return self::FAILURE;
        }
        $this->line("Previous files: {$folder}/previous");
        $this->warn('Only the files are restored. If the update applied migrations, restore the database dump from the same folder first (see DEVELOPMENT.md): never use migrate:rollback.');
        if (! $this->option('yes') && $this->input->isInteractive() && ! $this->confirm('Restore the previous version?', false)) {
            return self::FAILURE;
        }
        try {
            $swapper->rollback(base_path(), $folder.'/previous');
        } catch (UpdateException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        foreach ($this->option('up') ? [['optimize:clear'], ['up']] : [['optimize:clear']] as $arguments) {
            (new Process([PHP_BINARY, base_path('artisan'), ...$arguments, '--no-interaction'], base_path()))->run();
        }
        $status->put('rolled_back', ['step' => null, 'finished_at' => now('UTC')->toIso8601String(), 'message' => 'The previous files were restored.']);
        $this->info('The previous files are back.'.($this->option('up') ? '' : ' The instance is still in maintenance mode if it was: run `php artisan up` once the database is consistent.'));

        return self::SUCCESS;
    }
}
