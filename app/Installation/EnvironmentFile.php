<?php

namespace App\Installation;

use Dotenv\Dotenv;
use RuntimeException;

class EnvironmentFile
{
    public function path(): string
    {
        return config('perysee.environment_path', base_path('.env'));
    }

    public function values(): array
    {
        return is_file($this->path()) ? Dotenv::parse(file_get_contents($this->path())) : [];
    }

    public function write(array $values): void
    {
        $path = $this->path();
        $contents = is_file($path) ? file_get_contents($path) : "APP_NAME=Perysee\nAPP_ENV=production\nAPP_DEBUG=false\nAPP_LOCALE=en\nSESSION_DRIVER=file\nCACHE_STORE=file\nQUEUE_CONNECTION=database\nLOG_CHANNEL=daily\n";
        foreach ($values as $key => $value) {
            if (! preg_match('/^[A-Z_]+$/', $key) || preg_match('/[\r\n\x00]/', (string) $value)) {
                throw new RuntimeException('Invalid environment value.');
            }
            $encoded = '"'.str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], (string) $value).'"';
            $pattern = '/^'.preg_quote($key, '/').'=.*$/m';
            $line = $key.'='.$encoded;
            $contents = preg_match($pattern, $contents)
                ? preg_replace_callback($pattern, fn () => $line, $contents)
                : rtrim($contents)."\n".$line."\n";
        }
        $temporary = tempnam(dirname($path), '.perysee-env-');
        try {
            if ($temporary === false || ! chmod($temporary, 0600) || file_put_contents($temporary, $contents) === false || ! rename($temporary, $path)) {
                throw new RuntimeException('Unable to save configuration. Check directory permissions.');
            }
        } finally {
            if ($temporary && is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
