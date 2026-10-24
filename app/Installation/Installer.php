<?php

namespace App\Installation;

use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class Installer
{
    public function assertAvailable(): void
    {
        abort_if(app(InstallationState::class)->isLocked(), 409, 'Installation is locked. Sign in to your instance.');
        try {
            $hasUsers = DB::connection()->getSchemaBuilder()->hasTable('users') && DB::table('users')->exists();
        } catch (Throwable) {
            $hasUsers = false;
        }
        abort_if($hasUsers, 409, 'An account already exists. Installation is blocked. See the recovery instructions.');
    }

    public function connect(array $data): Connection
    {
        config(['database.connections.mariadb' => array_merge(config('database.connections.mariadb'), [
            'url' => null, 'unix_socket' => '', 'host' => $data['db_host'], 'port' => (int) $data['db_port'],
            'database' => $data['db_database'], 'username' => $data['db_username'], 'password' => $data['db_password'] ?? '',
            'timezone' => '+00:00', 'engine' => 'InnoDB',
        ])]);
        DB::purge('mariadb');
        $connection = DB::connection('mariadb');
        $version = $connection->selectOne('SELECT VERSION() AS version')->version;
        if (! str_contains($version, 'MariaDB') || ! str_starts_with($version, '11.4.')) {
            throw ValidationException::withMessages(['database' => 'This release requires MariaDB 11.4.x.']);
        }

        return $connection;
    }

    public function prepareDatabase(array $data, string $url): void
    {
        $this->assertAvailable();
        if (($data['db_password'] ?? '') === '') {
            $existing = app(EnvironmentFile::class)->values();
            $sameDatabase = ($existing['DB_HOST'] ?? '') === $data['db_host']
                && (string) ($existing['DB_PORT'] ?? '') === (string) $data['db_port']
                && ($existing['DB_DATABASE'] ?? '') === $data['db_database']
                && ($existing['DB_USERNAME'] ?? '') === $data['db_username'];
            if ($sameDatabase) {
                $data['db_password'] = $existing['DB_PASSWORD'] ?? '';
            }
        }
        try {
            $connection = $this->connect($data);
            abort_if($connection->getSchemaBuilder()->hasTable('users') && $connection->table('users')->exists(), 409, 'This database already contains accounts. Installation is blocked.');
        } catch (HttpException|ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::warning('Installation database connection failed.', ['exception_type' => get_class($exception)]);
            throw ValidationException::withMessages(['database' => 'Unable to connect to MariaDB. Check the database settings and permissions.']);
        }
        app(EnvironmentFile::class)->write([
            'APP_URL' => $url, 'APP_LOCALE' => 'en', 'DB_CONNECTION' => 'mariadb', 'DB_URL' => '', 'DB_SOCKET' => '',
            'DB_HOST' => $data['db_host'], 'DB_PORT' => $data['db_port'], 'DB_DATABASE' => $data['db_database'],
            'DB_USERNAME' => $data['db_username'], 'DB_PASSWORD' => $data['db_password'] ?? '',
            'SESSION_DRIVER' => 'file', 'CACHE_STORE' => 'file', 'QUEUE_CONNECTION' => 'database',
        ]);
        DB::setDefaultConnection($connection->getName());
        try {
            $status = Artisan::call('migrate', ['--database' => $connection->getName(), '--force' => true]);
            if ($status !== 0) {
                throw new \RuntimeException('Migration failed.');
            }
        } catch (Throwable $exception) {
            Log::warning('Installation migrations failed.', ['exception_type' => get_class($exception)]);
            throw ValidationException::withMessages(['database' => 'Database saved, but migrations could not finish. Check database privileges and retry. Completed migrations will be preserved.']);
        }
    }

    public function createAdministrator(array $data): void
    {
        $this->assertAvailable();
        DB::transaction(function () use ($data): void {
            $administrator = new User(['name' => $data['name'], 'email' => strtolower($data['email']), 'password' => $data['password']]);
            $administrator->forceFill(['is_admin' => true])->save();
        });
        app(InstallationState::class)->complete();
    }

    public function configurationFingerprint(): string
    {
        return hash('sha256', json_encode(app(EnvironmentFile::class)->values()));
    }
}
