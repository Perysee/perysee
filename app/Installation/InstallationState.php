<?php

namespace App\Installation;

use Closure;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class InstallationState
{
    public function directory(): string
    {
        return config('perysee.installation_path', storage_path('app/private/installation'));
    }

    public function secretPath(): string
    {
        return $this->directory().'/secret';
    }

    public function isLocked(): bool
    {
        return is_file($this->directory().'/installed');
    }

    public function bootstrap(): void
    {
        $this->exclusive(function (): void {
            $environment = app(EnvironmentFile::class);
            $key = $environment->values()['APP_KEY'] ?? '';
            if ($key === '') {
                $key = config('app.key') ?: 'base64:'.base64_encode(random_bytes(32));
                $environment->write(['APP_KEY' => $key]);
            }
            config(['app.key' => $key]);
            if (! $this->isLocked() && ! is_file($this->secretPath())) {
                $this->privateWrite($this->secretPath(), bin2hex(random_bytes(32)));
            }
        });
    }

    public function acceptsSecret(string $secret): bool
    {
        return ! $this->isLocked() && is_file($this->secretPath())
            && hash_equals(trim(file_get_contents($this->secretPath())), $secret);
    }

    public function complete(): void
    {
        $this->privateWrite($this->directory().'/installed', gmdate(DATE_ATOM));
        if (is_file($this->secretPath()) && ! unlink($this->secretPath())) {
            throw new RuntimeException('Unable to remove installation secret.');
        }
    }

    public function exclusive(Closure $callback): mixed
    {
        if (! is_dir($this->directory()) && ! mkdir($this->directory(), 0700, true) && ! is_dir($this->directory())) {
            throw new RuntimeException('Unable to create private installation directory.');
        }
        $handle = fopen($this->directory().'/operation.lock', 'c');
        if ($handle === false) {
            throw new RuntimeException('Unable to open installation lock.');
        }
        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw ValidationException::withMessages(['installation' => 'Another installation is running. Please try again shortly.']);
        }
        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function privateWrite(string $path, string $contents): void
    {
        $temporary = tempnam($this->directory(), '.install-');
        try {
            if ($temporary === false || ! chmod($temporary, 0600) || file_put_contents($temporary, $contents) === false || ! rename($temporary, $path)) {
                throw new RuntimeException('Unable to save installation state.');
            }
        } finally {
            if ($temporary && is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
