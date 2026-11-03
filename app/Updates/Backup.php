<?php

declare(strict_types=1);

namespace App\Updates;

use App\Updates\Dump\DumpMethod;
use App\Updates\Dump\NativeDump;
use App\Updates\Dump\PhpDump;
use Illuminate\Support\Facades\File;

/**
 * What an update saves before it touches anything: a database dump, the environment file (with the application
 * key, which must never be regenerated) and the private files. Backups sit under storage/app/private/updates/,
 * which the web server never serves, and the oldest are removed after a successful update.
 */
final class Backup
{
    public const KEEP = 3;

    public function __construct(private readonly NativeDump $native, private readonly PhpDump $php) {}

    public function root(): string
    {
        return storage_path('app/private/updates');
    }

    /**
     * @return list<DumpMethod>
     */
    public function methods(): array
    {
        return [$this->native, $this->php];
    }

    /**
     * Creates a backup folder holding the dump, the environment file and the private files.
     *
     * @param  string  $method  "auto" (the best available), "native", "php", or "external" for a dump the administrator made
     * @param  ?string  $externalDump  path of that dump when $method is "external"
     * @return array{path: string, method: string, database: string, sha256: string}
     */
    public function create(string $fromVersion, string $method = 'auto', ?string $externalDump = null): array
    {
        $path = $this->root().'/backups/'.now('UTC')->format('Ymd-His').'-v'.$fromVersion;
        if (! is_dir($path) && ! mkdir($path, 0700, true) && ! is_dir($path)) {
            throw new UpdateException('The backup folder cannot be created.');
        }
        $database = $this->database($path, $method, $externalDump);
        $this->files($path);
        $record = ['path' => $path, 'method' => $database['method'], 'database' => $database['file'], 'sha256' => $database['sha256']];
        file_put_contents($path.'/backup.json', json_encode(['from' => $fromVersion, 'created_at' => now('UTC')->toIso8601String(), 'database_driver' => config('database.default'), 'method' => $database['method'], 'database' => $database['file'], 'sha256' => $database['sha256']], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        chmod($path.'/backup.json', 0600);

        return $record;
    }

    /**
     * @return array{file: string, method: string, sha256: string}
     */
    private function database(string $path, string $method, ?string $externalDump): array
    {
        if ($method === 'external') {
            if ($externalDump === null || ! is_file($externalDump) || filesize($externalDump) === 0) {
                throw new UpdateException('The database dump you named does not exist or is empty.');
            }
            $hash = hash_file('sha256', $externalDump);

            return ['file' => $externalDump, 'method' => 'external', 'sha256' => (string) $hash];
        }
        $chosen = $this->choose($method);
        $plain = $path.'/database.sql';
        try {
            $chosen->dump($plain);
        } catch (\Throwable $failure) {
            @unlink($plain);

            throw $failure instanceof UpdateException ? $failure : new UpdateException('The database dump failed: '.$failure->getMessage(), 0, $failure);
        }
        $file = $this->compress($plain);
        chmod($file, 0600);

        return ['file' => basename($file), 'method' => $chosen->name(), 'sha256' => (string) hash_file('sha256', $file)];
    }

    private function choose(string $method): DumpMethod
    {
        foreach ($this->methods() as $candidate) {
            if (($method === 'auto' || $method === $candidate->name()) && $candidate->available()) {
                return $candidate;
            }
        }

        throw new UpdateException($method === 'auto' ? 'No database dump method is available on this server. Dump the database yourself and pass --dump=external --dump-file=PATH.' : "The \"{$method}\" dump method is not available on this server.");
    }

    private function compress(string $plain): string
    {
        if (! function_exists('gzopen')) {
            return $plain;
        }
        $target = $plain.'.gz';
        $input = fopen($plain, 'rb');
        $output = gzopen($target, 'wb6');
        if ($input === false || $output === false) {
            return $plain;
        }
        while (! feof($input)) {
            gzwrite($output, (string) fread($input, 1048576));
        }
        fclose($input);
        gzclose($output);
        unlink($plain);

        return $target;
    }

    private function files(string $path): void
    {
        $environment = config('perysee.environment_path');
        if (is_file($environment)) {
            copy($environment, $path.'/env');
            chmod($path.'/env', 0600);
        }
        $private = storage_path('app/private');
        if (is_dir($private)) {
            $this->copyTree($private, $path.'/private-files', $private);
        }
    }

    /** Private files except the backups themselves, which would copy each other forever. */
    private function copyTree(string $from, string $to, string $base): void
    {
        File::ensureDirectoryExists($to, 0700);
        foreach (scandir($from) ?: [] as $name) {
            $source = $from.'/'.$name;
            if ($name === '.' || $name === '..' || $source === $base.'/updates' || is_link($source)) {
                continue;
            }
            if (is_dir($source)) {
                $this->copyTree($source, $to.'/'.$name, $base);
            } else {
                copy($source, $to.'/'.$name);
                chmod($to.'/'.$name, 0600);
            }
        }
    }

    /**
     * Newest backup folder that still holds the files of the version it replaced.
     */
    public function latestWithPreviousVersion(): ?string
    {
        foreach (array_reverse($this->all()) as $path) {
            if (is_file($path.'/previous/'.CodeSwapper::MANIFEST)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @return list<string> backup folders, oldest first
     */
    public function all(): array
    {
        $folders = glob($this->root().'/backups/*', GLOB_ONLYDIR) ?: [];
        sort($folders);

        return $folders;
    }

    /** Removes the oldest backups, keeping the newest {@see self::KEEP}. */
    public function prune(): void
    {
        $folders = $this->all();
        foreach (array_slice($folders, 0, max(0, count($folders) - self::KEEP)) as $folder) {
            (new CodeSwapper)->remove($folder);
        }
    }
}
