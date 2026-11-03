<?php

declare(strict_types=1);

namespace App\Updates\Dump;

use App\Updates\UpdateException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Dumps with the MariaDB client tool (mariadb-dump, or mysqldump on older installations) when the server has it.
 * It is the fastest and most faithful method. The password goes through a private option file, never through
 * the command line, where other accounts of a shared server could read it.
 */
final class NativeDump implements DumpMethod
{
    private const BINARIES = ['mariadb-dump', 'mysqldump'];

    public function name(): string
    {
        return 'native';
    }

    public function label(): string
    {
        return ($this->binary() ?? 'mariadb-dump').' (MariaDB client tool)';
    }

    public function available(): bool
    {
        return in_array(DB::getDriverName(), ['mariadb', 'mysql'], true) && $this->binary() !== null && function_exists('proc_open');
    }

    public function binary(): ?string
    {
        $finder = new ExecutableFinder;
        foreach (self::BINARIES as $name) {
            $path = $finder->find($name);
            if ($path !== null) {
                return $path;
            }
        }

        return null;
    }

    public function dump(string $path): void
    {
        $binary = $this->binary();
        if ($binary === null || ! $this->available()) {
            throw new UpdateException('mariadb-dump is not available on this server.');
        }
        $config = DB::connection()->getConfig();
        $options = tempnam(sys_get_temp_dir(), 'perysee-dump');
        if ($options === false) {
            throw new UpdateException('Could not create the private option file for mariadb-dump.');
        }
        chmod($options, 0600);
        try {
            file_put_contents($options, $this->optionFile($config));
            $command = [$binary, '--defaults-extra-file='.$options, '--single-transaction', '--quick', '--routines', '--triggers', '--default-character-set=utf8mb4', ...$this->compatibilityOptions($binary), '--result-file='.$path, (string) $config['database']];
            $process = new Process($command);
            $process->setTimeout(1800);
            $process->run();
            if (! $process->isSuccessful() || ! is_file($path) || filesize($path) === 0) {
                throw new UpdateException('mariadb-dump failed: '.trim($this->withoutSecrets($process->getErrorOutput(), $config)));
            }
        } finally {
            @unlink($options);
        }
    }

    /**
     * The MySQL 8 client reads information_schema.COLUMN_STATISTICS, which MariaDB does not have, and fails
     * unless told not to; MariaDB's own tool and older MySQL clients do not know that option and refuse it.
     *
     * @return list<string>
     */
    private function compatibilityOptions(string $binary): array
    {
        $version = new Process([$binary, '--version']);
        $version->run();
        $text = $version->getOutput();

        return ! str_contains($text, 'MariaDB') && preg_match('/\bVer (\d+)\./', $text, $match) === 1 && (int) $match[1] >= 8 ? ['--column-statistics=0'] : [];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function optionFile(array $config): string
    {
        $lines = ['[client]', 'user="'.addcslashes((string) ($config['username'] ?? ''), '"\\').'"', 'password="'.addcslashes((string) ($config['password'] ?? ''), '"\\').'"'];
        if (! empty($config['unix_socket'])) {
            $lines[] = 'socket="'.addcslashes((string) $config['unix_socket'], '"\\').'"';
        } else {
            $lines[] = 'host="'.addcslashes((string) ($config['host'] ?? '127.0.0.1'), '"\\').'"';
            $lines[] = 'port='.(int) ($config['port'] ?? 3306);
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function withoutSecrets(string $output, array $config): string
    {
        $password = (string) ($config['password'] ?? '');

        return $password === '' ? $output : str_replace($password, '***', $output);
    }
}
