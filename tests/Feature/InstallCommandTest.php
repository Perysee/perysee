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

    public function test_a_non_interactive_installation_migrates_creates_the_administrator_and_locks(): void
    {
        putenv('PERYSEE_DB_PASSWORD=test-password');
        putenv('PERYSEE_ADMIN_PASSWORD=Strong-password-123!');

        $this->artisan('perysee:install', $this->installOptions() + ['--no-interaction' => true])
            ->expectsOutputToContain('Perysee is installed')
            ->doesntExpectOutputToContain('test-password')
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
        $this->artisan('perysee:install', $this->installOptions() + ['--no-interaction' => true])
            ->expectsOutputToContain('Administrator password (shown once)')
            ->assertSuccessful();

        $this->assertTrue(User::firstOrFail()->is_admin);
    }

    public function test_the_public_url_must_be_an_https_origin(): void
    {
        putenv('PERYSEE_ADMIN_PASSWORD=Strong-password-123!');

        $this->artisan('perysee:install', $this->installOptions(['--url' => 'https://stats.example.com/app?x=1']) + ['--no-interaction' => true])->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }
}
