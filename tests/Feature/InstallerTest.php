<?php

namespace Tests\Feature;

use App\Installation\EnvironmentFile;
use App\Installation\InstallationState;
use App\Installation\Installer;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InstallerTest extends TestCase
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

    private function unlock(): void
    {
        $this->get('/install')->assertOk();
        $secret = trim(file_get_contents(app(InstallationState::class)->secretPath()));
        $this->post('/install/unlock', ['installation_secret' => $secret])->assertRedirect('/install');
    }

    private function databasePayload(): array
    {
        if (DB::getDriverName() === 'sqlite') {
            return ['db_host' => '127.0.0.1', 'db_port' => 13306, 'db_database' => 'perysee_test', 'db_username' => 'perysee_test', 'db_password' => 'test-password'];
        }

        return ['db_host' => config('database.connections.mariadb.host', '127.0.0.1'), 'db_port' => config('database.connections.mariadb.port', 13306), 'db_database' => config('database.connections.mariadb.database', 'perysee_test'), 'db_username' => config('database.connections.mariadb.username', 'perysee_test'), 'db_password' => config('database.connections.mariadb.password', '')];
    }

    public function test_first_opening_displays_diagnostics_without_disclosing_secret(): void
    {
        $this->get('/')->assertRedirect('/install');
        $response = $this->get('/install')->assertOk()->assertSee('Install Perysee')->assertSee('installation/secret');
        $response->assertDontSee(trim(file_get_contents(app(InstallationState::class)->secretPath())));
    }

    public function test_invalid_secret_and_skipping_steps_are_rejected_without_flashing_secrets(): void
    {
        $this->get('/install');
        $this->post('/install/unlock', ['installation_secret' => 'incorrect'])->assertSessionHasErrors('installation_secret')->assertSessionMissing('_old_input.installation_secret');
        $this->post('/install/database', $this->databasePayload())->assertForbidden();
        $this->post('/install/administrator', [])->assertForbidden();
    }

    public function test_configuration_migrations_admin_creation_and_locking(): void
    {
        $this->unlock();
        $this->post('/install/database', $this->databasePayload())->assertSessionHasNoErrors()->assertRedirect('/install');
        $this->get('/install')->assertSee('Create your administrator');
        $this->post('/install/administrator', ['name' => 'Owner', 'email' => 'OWNER@example.com', 'password' => 'Strong-password-123!', 'password_confirmation' => 'Strong-password-123!'])->assertSessionHasNoErrors()->assertRedirect('/login');
        $user = User::firstOrFail();
        $this->assertSame('owner@example.com', $user->email);
        $this->assertTrue($user->is_admin);
        $this->assertTrue(app(InstallationState::class)->isLocked());
        $this->assertFileDoesNotExist(app(InstallationState::class)->secretPath());
        $this->get('/install')->assertStatus(409);
        $this->post('/install/database', $this->databasePayload())->assertStatus(409);
    }

    public function test_existing_user_blocks_reinstallation_even_if_file_lock_is_missing(): void
    {
        User::factory()->create();
        $this->get('/install')->assertStatus(409);
        $this->post('/install/database', $this->databasePayload())->assertStatus(409);
    }

    public function test_database_errors_are_generic_and_configuration_is_not_replaced(): void
    {
        $this->unlock();
        $before = file_get_contents(app(EnvironmentFile::class)->path());
        $this->partialMock(Installer::class, function ($mock): void {
            $mock->shouldReceive('connect')->andThrow(new \RuntimeException('private SQL password'));
        });
        $this->post('/install/database', $this->databasePayload())->assertSessionHasErrors('database')->assertSessionMissing('_old_input.db_password');
        $this->assertSame($before, file_get_contents(app(EnvironmentFile::class)->path()));
        $this->assertStringNotContainsString('private SQL password', json_encode(session('errors')->all()));
    }

    public function test_an_empty_password_field_preserves_existing_credentials_for_the_same_database(): void
    {
        $this->unlock();
        $payload = $this->databasePayload();
        $this->post('/install/database', $payload)->assertSessionHasNoErrors();
        $this->post('/install/database', array_merge($payload, ['db_password' => '']))->assertSessionHasNoErrors();
        $this->assertSame($payload['db_password'], app(EnvironmentFile::class)->values()['DB_PASSWORD']);
    }

    public function test_migrations_can_be_retried_before_an_administrator_is_created(): void
    {
        $this->unlock();
        $this->post('/install/database', $this->databasePayload())->assertSessionHasNoErrors();
        $this->post('/install/database', $this->databasePayload())->assertSessionHasNoErrors();
        $this->assertDatabaseCount('users', 0);
        $this->assertFalse(app(InstallationState::class)->isLocked());
    }

    public function test_migration_failure_preserves_settings_and_can_be_resumed(): void
    {
        $this->unlock();
        $console = app(Kernel::class);
        Artisan::shouldReceive('call')->once()->with('migrate', \Mockery::type('array'))->andReturn(1);
        $this->post('/install/database', $this->databasePayload())->assertSessionHasErrors('database');
        $this->assertSame('perysee_test', app(EnvironmentFile::class)->values()['DB_DATABASE']);
        $this->post('/install/administrator', [])->assertForbidden();
        Artisan::swap($console);
        $this->post('/install/database', $this->databasePayload())->assertSessionHasNoErrors();
        $this->get('/install')->assertSee('Create your administrator');
    }
}
