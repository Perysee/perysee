<?php

namespace App\Installation;

use Illuminate\Http\Request;

class Diagnostics
{
    public function checks(Request $request): array
    {
        $extensions = ['ctype', 'curl', 'dom', 'fileinfo', 'filter', 'hash', 'mbstring', 'openssl', 'pcre', 'pdo', 'pdo_mysql', 'session', 'tokenizer', 'xml'];
        $missing = array_values(array_filter($extensions, fn (string $extension) => ! extension_loaded($extension)));
        $script = $request->server('SCRIPT_FILENAME');
        $documentRoot = $request->server('DOCUMENT_ROOT');
        $public = realpath(public_path());
        $publicEntry = (! $script || realpath($script) === realpath(public_path('index.php')))
            && (! $documentRoot || realpath($documentRoot) === $public);
        $environment = app(EnvironmentFile::class)->path();

        return [
            ['label' => 'PHP 8.3 or later', 'ok' => PHP_VERSION_ID >= 80300],
            ['label' => $missing ? 'Missing extensions: '.implode(', ', $missing) : 'Required PHP extensions', 'ok' => $missing === []],
            ['label' => 'Writable storage and bootstrap/cache', 'ok' => is_writable(storage_path()) && is_writable(storage_path('framework/sessions')) && is_writable(storage_path('framework/views')) && is_writable(base_path('bootstrap/cache'))],
            ['label' => 'Writable configuration directory', 'ok' => is_writable(dirname($environment)) && (! is_file($environment) || is_writable($environment))],
            ['label' => 'HTTPS (required in production)', 'ok' => $request->isSecure() || app()->environment('local', 'testing')],
            ['label' => 'Document root points to public/', 'ok' => $publicEntry],
            ['label' => 'Configuration cache disabled during installation', 'ok' => ! app()->configurationIsCached()],
            ['label' => 'Debug mode disabled in production', 'ok' => ! config('app.debug') || app()->environment('local', 'testing')],
        ];
    }

    public function passes(Request $request): bool
    {
        return ! in_array(false, array_column($this->checks($request), 'ok'), true);
    }
}
