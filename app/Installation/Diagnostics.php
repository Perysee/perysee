<?php

namespace App\Installation;

use Illuminate\Http\Request;

class Diagnostics
{
    public function checks(Request $request): array
    {
        $script = $request->server('SCRIPT_FILENAME');
        $documentRoot = $request->server('DOCUMENT_ROOT');
        $public = realpath(public_path());
        $publicEntry = (! $script || realpath($script) === realpath(public_path('index.php')))
            && (! $documentRoot || realpath($documentRoot) === $public);

        return [
            $this->php(),
            $this->extensions(),
            $this->storage(),
            $this->configurationDirectory(),
            ['label' => 'HTTPS (required in production)', 'ok' => $request->isSecure() || app()->environment('local', 'testing')],
            ['label' => 'Document root points to public/', 'ok' => $publicEntry],
            $this->configurationCache(),
            ['label' => 'Debug mode disabled in production', 'ok' => ! config('app.debug') || app()->environment('local', 'testing')],
        ];
    }

    /** The checks that make sense without a web request, for installation from a terminal. */
    public function runtimeChecks(): array
    {
        return [$this->php(), $this->extensions(), $this->storage(), $this->configurationDirectory(), $this->configurationCache()];
    }

    public function passes(Request $request): bool
    {
        return ! in_array(false, array_column($this->checks($request), 'ok'), true);
    }

    public function runtimePasses(): bool
    {
        return ! in_array(false, array_column($this->runtimeChecks(), 'ok'), true);
    }

    private function php(): array
    {
        return ['label' => 'PHP 8.3 or later', 'ok' => PHP_VERSION_ID >= 80300];
    }

    private function extensions(): array
    {
        $extensions = ['ctype', 'curl', 'dom', 'fileinfo', 'filter', 'hash', 'mbstring', 'openssl', 'pcre', 'pdo', 'pdo_mysql', 'session', 'tokenizer', 'xml'];
        $missing = array_values(array_filter($extensions, fn (string $extension) => ! extension_loaded($extension)));

        return ['label' => $missing ? 'Missing extensions: '.implode(', ', $missing) : 'Required PHP extensions', 'ok' => $missing === []];
    }

    private function storage(): array
    {
        return ['label' => 'Writable storage and bootstrap/cache', 'ok' => is_writable(storage_path()) && is_writable(storage_path('framework/sessions')) && is_writable(storage_path('framework/views')) && is_writable(base_path('bootstrap/cache'))];
    }

    private function configurationDirectory(): array
    {
        $environment = app(EnvironmentFile::class)->path();

        return ['label' => 'Writable configuration directory', 'ok' => is_writable(dirname($environment)) && (! is_file($environment) || is_writable($environment))];
    }

    private function configurationCache(): array
    {
        return ['label' => 'Configuration cache disabled during installation', 'ok' => ! app()->configurationIsCached()];
    }
}
