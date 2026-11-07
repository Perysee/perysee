<?php

namespace App\Console\Commands;

use App\Updates\UpdateException;
use App\Updates\UpdateOptions;
use App\Updates\UpdateRunner;
use App\Updates\UpdateStatus;
use Illuminate\Console\Command;

class UpdateApplication extends Command
{
    protected $signature = 'perysee:update
        {--tag= : Install this release (vX.Y.Z) instead of the latest stable one}
        {--archive= : Install a release archive you downloaded (perysee.zip); needs --sha256}
        {--sha256= : Published SHA-256 of that archive (from the SHA256SUMS file of the release)}
        {--dump=auto : Database dump method: auto, native (mariadb-dump), php, or external (you made the dump yourself)}
        {--dump-file= : The dump you made yourself, with --dump=external}
        {--requested : Run only when an administrator asked for an update from the web page (used by the scheduler)}
        {--yes : Do not ask for confirmation}';

    protected $description = 'Update Perysee to a published release, with backup, maintenance mode and rollback of the files';

    public function handle(UpdateRunner $runner, UpdateStatus $status): int
    {
        if (config('perysee.install_mode') === 'docker') {
            if ($this->option('requested')) {
                return self::SUCCESS;
            }
            $this->error('This instance runs in Docker, where the code lives in the image. Update it by running the installer again: install.sh --mode docker --upgrade --target <installation folder>');

            return self::FAILURE;
        }
        $dump = (string) $this->option('dump');
        if ($this->option('requested')) {
            $request = $status->requested();
            if ($request === null) {
                return self::SUCCESS;
            }
            $status->clearRequest();
            $options = new UpdateOptions(version: $request['tag'], dump: $request['dump']);
        } else {
            if (! in_array($dump, ['auto', 'native', 'php', 'external'], true)) {
                $this->error('Use --dump=auto, native, php or external.');

                return self::FAILURE;
            }
            $options = new UpdateOptions($this->option('tag') ?: null, $this->option('archive') ?: null, $this->option('sha256') ?: null, $dump, $this->option('dump-file') ?: null);
            if (! $this->option('yes') && $this->input->isInteractive() && ! $this->confirm('This puts the instance in maintenance mode while it updates. Continue?', true)) {
                return self::FAILURE;
            }
        }
        try {
            $runner->run($options, fn (string $line) => $this->line($line));
        } catch (UpdateException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
