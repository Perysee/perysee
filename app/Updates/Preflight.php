<?php

declare(strict_types=1);

namespace App\Updates;

use Illuminate\Support\Facades\DB;

/**
 * Everything that can be known before the update starts changing things. Any problem found stops it.
 */
final class Preflight
{
    public function __construct(private readonly CodeSwapper $swapper) {}

    /**
     * @return list<string> human-readable problems, empty when the update can start
     */
    public function problems(string $staged, string $archive, string $live, string $previous, string $targetVersion): array
    {
        $problems = [];
        if (PHP_SAPI !== 'cli') {
            $problems[] = 'An update must be started from the command line or the scheduler, not from a web request.';
        }
        if (! Version::isNewer($targetVersion, (string) config('perysee.version'))) {
            $problems[] = "Version {$targetVersion} is not newer than the installed version ".config('perysee.version').'.';
        }
        $required = $this->requiredPhp($staged);
        if ($required !== null && $required > PHP_VERSION_ID) {
            $problems[] = 'This release needs PHP '.intdiv($required, 10000).'.'.intdiv($required % 10000, 100).' or newer; this server runs '.PHP_VERSION.'.';
        }
        $free = @disk_free_space($live);
        $needed = (int) (filesize($archive) ?: 0) * 4 + 256 * 1024 * 1024;
        if ($free !== false && $free < $needed) {
            $problems[] = 'Not enough free disk space: '.intdiv($needed, 1048576).' MiB are needed for the backup and the new version.';
        }
        if (! is_file(config('perysee.environment_path'))) {
            $problems[] = 'The environment file is missing; this instance cannot be updated safely.';
        }
        try {
            DB::connection()->getPdo();
        } catch (\Throwable) {
            $problems[] = 'The database cannot be reached.';
        }
        if (! is_executable(PHP_BINARY) || ! function_exists('proc_open')) {
            $problems[] = 'The PHP command line binary cannot be run, which the update needs to apply migrations with the new version.';
        }

        return array_merge($problems, $this->swapper->problems($staged, $live, $previous));
    }

    /**
     * The minimum PHP version of the release, read from the platform check Composer generated.
     */
    public function requiredPhp(string $staged): ?int
    {
        $check = @file_get_contents($staged.'/vendor/composer/platform_check.php');

        return $check !== false && preg_match('/PHP_VERSION_ID >= (\d+)/', $check, $match) === 1 ? (int) $match[1] : null;
    }
}
