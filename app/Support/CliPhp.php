<?php

declare(strict_types=1);

namespace App\Support;

use Closure;

/**
 * The PHP command line that should run the scheduler. PHP_BINARY is wrong for that during a web request: it is the
 * web server's own binary (lsphp, php-fpm, php-cgi), which does not run `artisan` like the CLI does, and on shared
 * hosting it is often a wrapper whose real version differs from the default `php` of the terminal.
 *
 * The CLI that was built together with the running PHP is the right one: PHP_BINDIR names the directory of that build
 * (/opt/alt/php83/usr/bin, /opt/cpanel/ea-php83/root/usr/bin, /usr/local/bin in the official Docker image), so its
 * `php` has exactly the version and extensions of the website. Other well-known layouts are tried by version.
 */
final class CliPhp
{
    private readonly string $version;

    /** @param Closure(string): bool|null $executable replaces the filesystem check, for tests */
    public function __construct(
        private readonly string $sapi = PHP_SAPI,
        private readonly string $binary = PHP_BINARY,
        private readonly string $binDirectory = PHP_BINDIR,
        string $version = PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION,
        private readonly ?Closure $executable = null,
        private readonly ?bool $restricted = null,
    ) {
        $this->version = $version;
    }

    public function path(): string
    {
        if (in_array($this->sapi, ['cli', 'phpdbg'], true)) {
            return $this->binary;
        }
        // open_basedir forbids looking outside the allowed directories (and warns): trust the build directory.
        if ($this->restricted ?? ini_get('open_basedir') !== '') {
            return $this->binDirectory.'/php';
        }
        foreach ($this->candidates() as $candidate) {
            if ($this->isExecutable($candidate)) {
                return $candidate;
            }
        }

        return 'php';
    }

    /** The crontab line that runs the scheduler every minute from $directory, keeping only errors in a log. */
    public function cronLine(string $directory): string
    {
        return '* * * * * cd '.escapeshellarg(rtrim($directory, '/')).' && '.escapeshellarg($this->path()).' artisan schedule:run > /dev/null 2>> storage/logs/cron.log';
    }

    /** @return list<string> */
    private function candidates(): array
    {
        $dotted = $this->version;
        $compact = str_replace('.', '', $dotted);

        return array_values(array_unique([
            $this->binDirectory.'/php'.$dotted,
            $this->binDirectory.'/php',
            "/opt/cpanel/ea-php{$compact}/root/usr/bin/php",
            "/opt/alt/php{$compact}/usr/bin/php",
            "/opt/plesk/php/{$dotted}/bin/php",
            "/opt/remi/php{$compact}/root/usr/bin/php",
            "/usr/bin/php{$dotted}",
        ]));
    }

    private function isExecutable(string $path): bool
    {
        return $this->executable !== null ? ($this->executable)($path) : is_file($path) && is_executable($path);
    }
}
