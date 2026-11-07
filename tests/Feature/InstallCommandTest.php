<?php

namespace Tests\Feature;

use App\Installation\EnvironmentFile;
use App\Installation\InstallationState;
use App\Installation\Installer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InstallCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() === 'sqlite') {
            $this->partialMock(Installer::class, function ($mock): void {
                $mock->shouldReceive('connect')->andReturnUsing(fn (array $data) => DB::connection());
            });
        }
    }

    protected function tearDown(): void
    {
        putenv('PERYSEE_DB_PASSWORD');
        putenv('PERYSEE_ADMIN_PASSWORD');
        parent::tearDown();
    }

    private function installOptions(array $overrides = []): array
    {
        $settings = DB::getDriverName() === 'sqlite'
            ? ['host' => '127.0.0.1', 'port' => 13306, 'database' => 'perysee_test', 'username' => 'perysee_test']
            : config('database.connections.mariadb');

        return array_merge([
            '--url' => 'https://stats.example.com',
            '--db-host' => $settings['host'], '--db-port' => $settings['port'],
            '--db-name' => $settings['database'], '--db-user' => $settings['username'],
            '--admin-name' => 'Owner', '--admin-email' => 'OWNER@example.com',
        ], $overrides);
    }

    /** The database password: SQLite replaces the connection, MariaDB needs the real one of the test database. */
    private function databasePassword(): string
    {
        return DB::getDriverName() === 'sqlite' ? 'test-password' : (string) config('database.connections.mariadb.password', '');
    }

    public function test_a_non_interactive_installation_migrates_creates_the_administrator_and_locks(): void
    {
        putenv('PERYSEE_DB_PASSWORD='.$this->databasePassword());
        putenv('PERYSEE_ADMIN_PASSWORD=Strong-password-123!');

        $this->artisan('perysee:install', $this->installOptions() + ['--no-interaction' => true])
            ->expectsOutputToContain('Perysee is installed')
            ->doesntExpectOutputToContain($this->databasePassword())
            ->doesntExpectOutputToContain('Strong-password-123!')
            ->assertSuccessful();

        $user = User::firstOrFail();
        $this->assertSame('owner@example.com', $user->email);
        $this->assertTrue($user->is_admin);
        $this->assertTrue(app(InstallationState::class)->isLocked());
        $this->assertFileDoesNotExist(app(InstallationState::class)->secretPath());
        $values = app(EnvironmentFile::class)->values();
        $this->assertSame('https://stats.example.com', $values['APP_URL']);
        $this->assertNotSame('', $values['APP_KEY']);
    }

    public function test_an_existing_installation_is_never_overwritten(): void
    {
        User::factory()->create();
        putenv('PERYSEE_ADMIN_PASSWORD=Strong-password-123!');

        $this->artisan('perysee:install', $this->installOptions() + ['--no-interaction' => true])->assertFailed();

        $this->assertDatabaseCount('users', 1);
    }

    public function test_a_weak_administrator_password_is_rejected_before_anything_is_written(): void
    {
        putenv('PERYSEE_ADMIN_PASSWORD=short');

        $this->artisan('perysee:install', $this->installOptions() + ['--no-interaction' => true])->assertFailed();

        $this->assertDatabaseCount('users', 0);
        $this->assertFalse(app(InstallationState::class)->isLocked());
        $this->assertArrayNotHasKey('DB_DATABASE', app(EnvironmentFile::class)->values());
    }

    public function test_a_generated_administrator_password_is_shown_once_when_none_is_supplied(): void
    {
        putenv('PERYSEE_DB_PASSWORD='.$this->databasePassword());

        $this->artisan('perysee:install', $this->installOptions() + ['--no-interaction' => true])
            ->expectsOutputToContain('Administrator password (shown once)')
            ->assertSuccessful();

        $this->assertTrue(User::firstOrFail()->is_admin);
    }

    public function test_the_browser_option_prints_a_link_that_carries_the_secret_in_the_fragment(): void
    {
        $this->artisan('perysee:install', ['--browser' => true, '--url' => 'https://stats.example.com', '--no-interaction' => true])
            ->assertSuccessful();

        $state = app(InstallationState::class);
        $secret = trim(file_get_contents($state->secretPath()));
        $this->assertFalse($state->isLocked());
        $this->assertDatabaseCount('users', 0);
        $this->assertNotSame('', app(EnvironmentFile::class)->values()['APP_KEY']);
        $this->assertSame('https://stats.example.com/install#secret='.$secret, $this->installLink());
    }

    public function test_the_browser_link_reuses_the_same_secret_when_run_again(): void
    {
        $arguments = ['--browser' => true, '--url' => 'https://stats.example.com', '--no-interaction' => true];
        $this->artisan('perysee:install', $arguments)->assertSuccessful();
        $first = $this->installLink();
        $this->artisan('perysee:install', $arguments)->assertSuccessful();

        $this->assertSame($first, $this->installLink());
    }

    public function test_the_browser_option_refuses_an_installed_instance_and_a_bad_url(): void
    {
        $this->artisan('perysee:install', ['--browser' => true, '--url' => 'https://stats.example.com/app', '--no-interaction' => true])->assertFailed();

        User::factory()->create();
        $this->artisan('perysee:install', ['--browser' => true, '--url' => 'https://stats.example.com', '--no-interaction' => true])->assertFailed();
    }

    private function installLink(): string
    {
        return 'https://stats.example.com/install#secret='.trim(file_get_contents(app(InstallationState::class)->secretPath()));
    }

    public function test_the_public_url_must_be_an_https_origin(): void
    {
        putenv('PERYSEE_ADMIN_PASSWORD=Strong-password-123!');

        $this->artisan('perysee:install', $this->installOptions(['--url' => 'https://stats.example.com/app?x=1']) + ['--no-interaction' => true])->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }
}
