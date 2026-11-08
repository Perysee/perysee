<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Generator;
use Illuminate\Console\Command;

/**
 * The `file` cache store (the default of the installer, and the one the country database import uses) never
 * deletes an expired entry unless something reads it again: rate limiter counters, which have a key per visitor
 * address, pile up as files, and shared hosting counts inodes. This removes the expired ones.
 *
 * The store writes `<expiry timestamp, 10 digits><serialized value>` in `<path>/ab/cd/<sha1>`. Only files with that
 * name and prefix are touched, directories are left alone (the store recreates them without checking twice), and a
 * run looks at a bounded number of files, starting from another place every hour so that the whole tree is covered
 * over time.
 */
class PruneCache extends Command
{
    /** Files examined per run: the cost of an hourly pass does not grow with the cache. */
    private const MAX_SCANNED = 50000;

    protected $signature = 'perysee:prune-cache {--limit=1000 : Maximum expired files deleted per run}';

    protected $description = 'Delete expired entries of the file cache store, in bounded runs';

    public function handle(): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);
        if ($limit === false) {
            $this->error('Use a limit between 1 and 10000.');

            return self::FAILURE;
        }
        $directory = rtrim((string) config('cache.stores.file.path'), '/\\');
        if ($directory === '' || ! is_dir($directory)) {
            return self::SUCCESS;
        }
        $now = time();
        $scanned = $deleted = 0;
        foreach ($this->files($directory, intdiv($now, 3600)) as $file) {
            if (++$scanned > self::MAX_SCANNED || $deleted >= $limit) {
                break;
            }
            if ($this->expired($file, $now) && @unlink($file)) {
                $deleted++;
            }
        }
        $this->info("Removed {$deleted} expired cache files.");

        return self::SUCCESS;
    }

    /**
     * The entries of the store, one first-level directory after the other, beginning with the one the hour selects.
     *
     * @return Generator<int, string>
     */
    private function files(string $directory, int $hour): Generator
    {
        $roots = array_values(array_filter(scandir($directory) ?: [], fn (string $name): bool => preg_match('/^[0-9a-f]{2}$/', $name) === 1 && is_dir($directory.'/'.$name)));
        if ($roots === []) {
            return;
        }
        $start = $hour % count($roots);
        foreach (array_merge(array_slice($roots, $start), array_slice($roots, 0, $start)) as $root) {
            foreach (scandir($directory.'/'.$root) ?: [] as $second) {
                $path = $directory.'/'.$root.'/'.$second;
                if (preg_match('/^[0-9a-f]{2}$/', $second) !== 1 || ! is_dir($path)) {
                    continue;
                }
                foreach (scandir($path) ?: [] as $name) {
                    if (preg_match('/^[0-9a-f]{40}$/', $name) === 1) {
                        yield $path.'/'.$name;
                    }
                }
            }
        }
    }

    private function expired(string $file, int $now): bool
    {
        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            return false;
        }
        $prefix = (string) fread($handle, 10);
        fclose($handle);

        return strlen($prefix) === 10 && ctype_digit($prefix) && (int) $prefix < $now;
    }
}
