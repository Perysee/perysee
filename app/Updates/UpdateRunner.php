<?php

declare(strict_types=1);

namespace App\Updates;

use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Updates the application to a published release: download, integrity check, unpacking into a staging folder,
 * pre-flight checks, backup, maintenance mode, file swap, then migrations by the new version in a fresh process.
 *
 * Nothing changes before the backup exists. If anything fails after the files were swapped, the previous files
 * are put back. The database is never rolled back automatically: a half-applied migration cannot be undone
 * safely, so the instance then stays in maintenance mode until the backup is restored (see DEVELOPMENT.md).
 */
final class UpdateRunner
{
    public function __construct(
        private readonly ReleaseFeed $feed,
        private readonly PackageVerifier $verifier,
        private readonly ArchiveExtractor $extractor,
        private readonly Preflight $preflight,
        private readonly Backup $backup,
        private readonly CodeSwapper $swapper,
        private readonly UpdateStatus $status,
    ) {}

    /**
     * @param  Closure(string): void  $log
     * @return string the version now installed
     */
    public function run(UpdateOptions $options, Closure $log): string
    {
        return $this->exclusive(fn (): string => $this->update($options, $log));
    }

    /**
     * @param  Closure(string): void  $log
     */
    private function update(UpdateOptions $options, Closure $log): string
    {
        $from = $this->feed->currentVersion();
        $work = $this->backup->root().'/work-'.bin2hex(random_bytes(4));
        $down = false;
        $swapped = false;
        $previous = null;
        $migrationsBefore = 0;
        $this->status->begin(['step' => 'download', 'from' => $from, 'to' => null, 'started_at' => now('UTC')->toIso8601String(), 'message' => null, 'backup' => null]);
        try {
            if (! is_dir($work) && ! mkdir($work, 0700, true) && ! is_dir($work)) {
                throw new UpdateException('The working folder cannot be created.');
            }
            [$archive, $expected] = $this->obtain($options, $work, $log);
            if ($archive === null) {
                $this->status->put('idle', ['step' => null, 'message' => "Already up to date ({$from})."]);
                $log("Already up to date ({$from}).");

                return $from;
            }
            $log('Checking the archive against its SHA-256 checksum');
            $this->verifier->assertMatches($archive, $expected);
            $this->status->put('running', ['step' => 'unpack']);
            $log('Unpacking into a staging folder');
            $release = $this->extractor->extract($archive, $work.'/staged');
            $target = $release['version'];
            if (strcasecmp($release['repository'], (string) config('perysee.update.repository')) !== 0) {
                throw new UpdateException('The archive was built for another repository than the one this instance updates from.');
            }
            $this->status->put('running', ['to' => $target, 'step' => 'preflight']);
            if ($options->version !== null && Version::normalize($options->version) !== $target) {
                throw new UpdateException("The archive contains {$target}, not the requested {$options->version}.");
            }
            $log('Pre-flight checks');
            $problems = $this->preflight->problems($work.'/staged', $archive, base_path(), $work.'/previous-probe', $target);
            if ($problems !== []) {
                throw new UpdateException("The update cannot start:\n- ".implode("\n- ", $problems));
            }
            $this->status->put('running', ['step' => 'backup']);
            $log('Backing up the database, the environment file and the private files');
            $backup = $this->backup->create($from, $options->dump, $options->dumpFile);
            $log("Backup written to {$backup['path']} (database: {$backup['method']})");
            $this->status->put('running', ['step' => 'maintenance', 'backup' => $backup['path']]);
            $migrationsBefore = $this->migrationCount();
            $this->preload();
            Artisan::call('down', ['--retry' => 60, '--render' => 'errors::503']);
            $down = true;
            $log('Maintenance mode on: collection answers 503 until the update ends');
            $previous = $backup['path'].'/previous';
            $this->status->put('running', ['step' => 'swap']);
            $log('Replacing the application files');
            $this->swapper->swap($work.'/staged', base_path(), $previous);
            $swapped = true;
            $this->status->put('running', ['step' => 'migrate']);
            $log("Applying {$target} (migrations, caches)");
            $this->finalize($target, $log);
            $this->status->put('succeeded', ['step' => null, 'from' => $from, 'to' => $target, 'finished_at' => now('UTC')->toIso8601String(), 'message' => "Updated from {$from} to {$target}."]);
            $this->backup->prune();
            $log("Updated from {$from} to {$target}");

            return $target;
        } catch (Throwable $failure) {
            $message = $failure instanceof UpdateException ? $failure->getMessage() : 'Unexpected error: '.$failure->getMessage();
            if ($swapped && $previous !== null) {
                $message = $this->recover($message, $previous, $migrationsBefore, $log);
            } elseif ($down) {
                Artisan::call('up');
                $log('Maintenance mode off: nothing was changed');
                $this->status->put('failed', ['step' => null, 'finished_at' => now('UTC')->toIso8601String(), 'message' => $message]);
            } else {
                $this->status->put('failed', ['step' => null, 'finished_at' => now('UTC')->toIso8601String(), 'message' => $message]);
            }

            throw new UpdateException($message, 0, $failure);
        } finally {
            $this->swapper->remove($work);
        }
    }

    /**
     * Restores the previous files, then decides whether the instance may reopen: only if no migration ran.
     *
     * @param  Closure(string): void  $log
     */
    private function recover(string $message, string $previous, int $migrationsBefore, Closure $log): string
    {
        try {
            $this->swapper->rollback(base_path(), $previous);
            $log('The previous version was restored');
        } catch (Throwable $rollbackFailure) {
            $text = "{$message}\nThe previous files could NOT be restored automatically ({$rollbackFailure->getMessage()}). The instance stays in maintenance mode: restore them from {$previous}.";
            $this->status->put('needs_attention', ['step' => null, 'finished_at' => now('UTC')->toIso8601String(), 'message' => $text]);

            return $text;
        }
        $this->artisan(['optimize:clear']);
        if ($this->migrationCount() === $migrationsBefore) {
            $this->artisan(['up']);
            $text = "{$message}\nThe previous version was restored and no migration had run: the instance is back online.";
            $this->status->put('failed', ['step' => null, 'finished_at' => now('UTC')->toIso8601String(), 'message' => $text]);

            return $text;
        }
        $text = "{$message}\nThe previous files were restored, but some migrations had already run. The instance stays in maintenance mode: restore the database backup, then run `php artisan up` (see DEVELOPMENT.md).";
        $this->status->put('needs_attention', ['step' => null, 'finished_at' => now('UTC')->toIso8601String(), 'message' => $text]);

        return $text;
    }

    /**
     * Runs the new version in a fresh process: only code that was not loaded before the swap can be trusted
     * to be consistent with the files now on disk.
     *
     * @param  Closure(string): void  $log
     */
    private function finalize(string $target, Closure $log): void
    {
        $process = new Process([PHP_BINARY, base_path('artisan'), 'perysee:update:finalize', '--expect='.$target, '--no-interaction'], base_path());
        $process->setTimeout(1800);
        $process->run(function (string $type, string $buffer) use ($log): void {
            foreach (preg_split('/\R/', trim($buffer)) ?: [] as $line) {
                if ($line !== '') {
                    $log("  {$line}");
                }
            }
        });
        if (! $process->isSuccessful()) {
            throw new UpdateException("The new version could not finish the update (exit code {$process->getExitCode()}).");
        }
    }

    /**
     * Downloads the release, or takes the archive named on the command line.
     *
     * @param  Closure(string): void  $log
     * @return array{0: ?string, 1: string} the archive path (null when already up to date) and its expected SHA-256
     */
    private function obtain(UpdateOptions $options, string $work, Closure $log): array
    {
        if ($options->archive !== null) {
            if (! is_file($options->archive)) {
                throw new UpdateException('The archive you named does not exist.');
            }
            if ($options->sha256 === null) {
                throw new UpdateException('Pass the published checksum of the archive with --sha256.');
            }

            return [$options->archive, $options->sha256];
        }
        $release = $options->version !== null ? $this->feed->forTag($options->version) : $this->feed->latest();
        if ($release === null) {
            throw new UpdateException($options->version !== null ? "Release {$options->version} was not found." : 'No published release was found.');
        }
        if ($options->version === null && ! Version::isNewer($release->tag, $this->feed->currentVersion())) {
            return [null, ''];
        }
        $log("Downloading {$release->tag}");
        $sums = $this->fetch($release->checksumsUrl);
        $archive = $work.'/'.PackageVerifier::ARCHIVE;
        $this->download($release->archiveUrl, $archive);

        return [$archive, $this->verifier->expectedHash($sums)];
    }

    private function fetch(string $url): string
    {
        $this->assertHttps($url);
        try {
            $response = $this->client(30)->get($url);
        } catch (ConnectionException) {
            throw new UpdateException('The release files cannot be downloaded from this server.');
        }
        if (! $response->successful() || strlen($response->body()) > 65536) {
            throw new UpdateException('The checksum list could not be downloaded (HTTP '.$response->status().').');
        }

        return $response->body();
    }

    private function download(string $url, string $path): void
    {
        $this->assertHttps($url);
        try {
            $response = $this->client(900)->connectTimeout(10)->sink($path)->get($url);
        } catch (ConnectionException) {
            throw new UpdateException('The release archive cannot be downloaded from this server.');
        }
        if (! $response->successful() || ! is_file($path) || filesize($path) === 0) {
            throw new UpdateException('The release archive could not be downloaded (HTTP '.$response->status().').');
        }
        if (filesize($path) > ArchiveExtractor::MAX_BYTES) {
            throw new UpdateException('The release archive is larger than expected.');
        }
    }

    /**
     * A redirect from a release address may only lead to another HTTPS address: assertHttps checks the first URL only.
     */
    private function client(int $timeout): PendingRequest
    {
        return Http::withHeaders(['User-Agent' => 'Perysee/'.$this->feed->currentVersion()])
            ->withOptions(['allow_redirects' => ['max' => 5, 'protocols' => ['https']]])
            ->timeout($timeout);
    }

    private function assertHttps(string $url): void
    {
        if (! str_starts_with($url, 'https://')) {
            throw new UpdateException('Releases are only downloaded over HTTPS.');
        }
    }

    private function migrationCount(): int
    {
        return DB::table('migrations')->count();
    }

    /**
     * @param  list<string>  $arguments
     */
    private function artisan(array $arguments): void
    {
        $process = new Process([PHP_BINARY, base_path('artisan'), ...$arguments, '--no-interaction'], base_path());
        $process->setTimeout(120);
        $process->run();
    }

    /**
     * Loads every class the failure path needs, so it never has to autoload from files that are being replaced.
     */
    private function preload(): void
    {
        foreach ([Process::class, UpdateException::class, CodeSwapper::class, UpdateStatus::class, Backup::class, DB::class, Artisan::class, Carbon::class, Builder::class] as $class) {
            class_exists($class);
        }
    }

    /**
     * Only one update at a time, even if a person and the scheduler start one together.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function exclusive(Closure $callback): mixed
    {
        $directory = $this->backup->root();
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new UpdateException('The update folder cannot be created.');
        }
        $handle = fopen($directory.'/update.lock', 'c');
        if ($handle === false || ! flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }

            throw new UpdateException('Another update is running.');
        }
        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
