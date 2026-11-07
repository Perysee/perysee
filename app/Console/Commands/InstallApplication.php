<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Installation\Diagnostics;
use App\Installation\InstallationState;
use App\Installation\Installer;
use App\Rules\BcryptPassword;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class InstallApplication extends Command
{
    protected $signature = 'perysee:install
        {--url= : Public origin of the instance, for example https://stats.example.com}
        {--db-host=127.0.0.1 : MariaDB host}
        {--db-port=3306 : MariaDB port}
        {--db-name= : Database name}
        {--db-user= : Database user}
        {--admin-name= : Administrator name}
        {--admin-email= : Administrator email}';

    protected $description = 'Install Perysee from the terminal: migrate the database, create the administrator and lock the installation. Passwords come from PERYSEE_DB_PASSWORD and PERYSEE_ADMIN_PASSWORD or a hidden prompt, never from arguments.';

    public function handle(Installer $installer, InstallationState $state, Diagnostics $diagnostics): int
    {
        try {
            $installer->assertAvailable();
            $input = $this->gather();
            $this->validateInput($input);
            $this->assertDiagnostics($diagnostics);
            $state->bootstrap();
            $state->exclusive(function () use ($installer, $input): void {
                $installer->prepareDatabase($input['database'], $input['url']);
                $installer->createAdministrator($input['administrator']);
            });
        } catch (HttpException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (ValidationException $exception) {
            $this->error($exception->validator->errors()->first());

            return self::FAILURE;
        }
        if ($input['generated']) {
            $this->line('Administrator password (shown once): '.$input['administrator']['password']);
        }
        $this->info("Perysee is installed. Sign in at {$input['url']}/login with {$input['administrator']['email']}.");

        return self::SUCCESS;
    }

    /** @return array{url: string, database: array<string, mixed>, administrator: array<string, string>, generated: bool} */
    private function gather(): array
    {
        $generated = false;
        $adminPassword = getenv('PERYSEE_ADMIN_PASSWORD');
        if ($adminPassword === false) {
            if ($this->input->isInteractive()) {
                $adminPassword = (string) $this->secret('Administrator password (at least 12 characters)');
                $confirmation = (string) $this->secret('Confirm administrator password');
                if ($adminPassword !== $confirmation) {
                    throw ValidationException::withMessages(['password' => 'The password confirmation does not match.']);
                }
            } else {
                $adminPassword = Str::password(24, symbols: false);
                $generated = true;
            }
        }
        $databasePassword = getenv('PERYSEE_DB_PASSWORD');
        if ($databasePassword === false) {
            $databasePassword = $this->input->isInteractive() ? (string) $this->secret('Database password (empty if none)') : '';
        }

        return [
            'url' => rtrim((string) ($this->option('url') ?: $this->askIfInteractive('Public HTTPS URL (origin only)')), '/'),
            'database' => [
                'db_host' => (string) $this->option('db-host'), 'db_port' => $this->option('db-port'),
                'db_database' => (string) ($this->option('db-name') ?: $this->askIfInteractive('Database name')),
                'db_username' => (string) ($this->option('db-user') ?: $this->askIfInteractive('Database user')),
                'db_password' => $databasePassword,
            ],
            'administrator' => [
                'name' => (string) ($this->option('admin-name') ?: $this->askIfInteractive('Administrator name')),
                'email' => (string) ($this->option('admin-email') ?: $this->askIfInteractive('Administrator email')),
                'password' => $adminPassword, 'password_confirmation' => $adminPassword,
            ],
            'generated' => $generated,
        ];
    }

    private function askIfInteractive(string $question): string
    {
        return $this->input->isInteractive() ? (string) $this->ask($question) : '';
    }

    /** The same rules as the browser installer, applied before anything is written. */
    private function validateInput(array $input): void
    {
        $validator = Validator::make([
            'url' => $input['url'], ...$input['database'], ...$input['administrator'],
        ], [
            'db_host' => ['required', 'string', 'max:253', 'regex:/^[a-zA-Z0-9.:-]+$/'],
            'db_port' => ['required', 'integer', 'between:1,65535'],
            'db_database' => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'db_username' => ['required', 'string', 'max:80', 'not_regex:/[\r\n\x00]/'],
            'db_password' => ['nullable', 'string', 'max:255', 'not_regex:/[\r\n\x00]/'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(12), new BcryptPassword],
        ]);
        $validator->validate();
        if (! $this->isOrigin($input['url'])) {
            throw ValidationException::withMessages(['url' => 'Provide an HTTPS origin without a path, credentials, query or fragment.']);
        }
    }

    private function isOrigin(string $url): bool
    {
        $parsed = parse_url($url);
        $secure = ($parsed['scheme'] ?? '') === 'https' || (($parsed['scheme'] ?? '') === 'http' && app()->environment('local', 'testing'));

        return filter_var($url, FILTER_VALIDATE_URL) !== false && $secure && ! empty($parsed['host'])
            && ! isset($parsed['user']) && ! isset($parsed['query']) && ! isset($parsed['fragment']) && empty($parsed['path']);
    }

    private function assertDiagnostics(Diagnostics $diagnostics): void
    {
        $failed = array_filter($diagnostics->runtimeChecks(), fn (array $check): bool => ! $check['ok']);
        if ($failed !== []) {
            throw new HttpException(422, 'Resolve the installation checks first: '.implode('; ', array_column($failed, 'label')));
        }
    }
}
