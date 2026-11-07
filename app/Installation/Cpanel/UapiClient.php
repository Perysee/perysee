<?php

declare(strict_types=1);

namespace App\Installation\Cpanel;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

/**
 * Calls cPanel's UAPI through the local `uapi` command, which acts as the account's own user: no API token and no
 * network call. Each function is answered as {"result": {"status": 1, "data": ..., "errors": ...}}.
 */
final class UapiClient
{
    public function __construct(private readonly ?string $binary = null) {}

    /** The uapi executable on this server, or null when it is not a cPanel account. */
    public static function locate(): ?string
    {
        $directories = array_merge(explode(PATH_SEPARATOR, (string) getenv('PATH')), ['/usr/local/cpanel/bin', '/usr/bin']);
        foreach ($directories as $directory) {
            if ($directory !== '' && is_file($directory.'/uapi') && is_executable($directory.'/uapi')) {
                return $directory.'/uapi';
            }
        }

        return null;
    }

    public function available(): bool
    {
        return ($this->binary ?? self::locate()) !== null;
    }

    /**
     * @param  array<string, scalar>  $arguments
     * @param  bool  $secret  keep the arguments off the command line (visible in the process list) by sending them
     *                        as JSON on standard input, falling back to the command line only if uapi refuses that
     *                        form before running anything
     */
    public function call(string $module, string $function, array $arguments = [], bool $secret = false): mixed
    {
        $binary = $this->binary ?? self::locate();
        if ($binary === null) {
            throw new ProvisioningException('The uapi command was not found. This does not look like a cPanel account.');
        }
        if ($secret) {
            $answer = $this->decode(Process::timeout(60)->input(json_encode($arguments, JSON_THROW_ON_ERROR))->run([$binary, '--output=json', '--input=json', $module, $function]));
            if ($answer !== null) {
                return $this->data($module, $function, $answer);
            }
        }
        $command = [$binary, '--output=json', $module, $function];
        foreach ($arguments as $name => $value) {
            $command[] = $name.'='.$value;
        }
        $result = Process::timeout(60)->run($command);
        $answer = $this->decode($result);
        if ($answer === null) {
            throw new ProvisioningException("uapi {$module} {$function} returned an unreadable answer (exit {$result->exitCode()}).");
        }

        return $this->data($module, $function, $answer);
    }

    private function decode(ProcessResult $result): ?array
    {
        $decoded = json_decode($result->output(), true);

        return is_array($decoded) ? ($decoded['result'] ?? $decoded) : null;
    }

    private function data(string $module, string $function, array $answer): mixed
    {
        if ((int) ($answer['status'] ?? 0) !== 1) {
            $errors = array_filter((array) ($answer['errors'] ?? []), 'is_string');
            throw new ProvisioningException("uapi {$module} {$function} failed: ".($errors === [] ? 'no reason given' : implode(' ', $errors)));
        }

        return $answer['data'] ?? null;
    }
}
