<?php

namespace Tests\Feature;

use App\Installation\Cpanel\UapiClient;
use App\Installation\EnvironmentFile;
use App\Installation\InstallationState;
use App\Installation\Installer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InstallCpanelCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(UapiClient::class, new UapiClient('/usr/local/cpanel/bin/uapi'));
        putenv('PERYSEE_ADMIN_PASSWORD=Strong-password-123!');
    }

    protected function tearDown(): void
    {
        putenv('PERYSEE_ADMIN_PASSWORD');
        parent::tearDown();
    }

    private function ok(mixed $data = null): array
    {
        return ['result' => ['status' => 1, 'data' => $data, 'errors' => null]];
    }

    private function cpanel(array $overrides = []): void
    {
        $answers = array_merge([
            'Variables get_user_information' => $this->ok(['user' => 'cpuser', 'home' => dirname(base_path())]),
            'Mysql get_restrictions' => $this->ok(['prefix' => 'cpuser_', 'max_database_name_length' => 63, 'max_username_length' => 31]),
            'LangPHP php_get_installed_versions' => $this->ok(['ea-php83']),
            'DomainInfo domains_data' => $this->ok(['main_domain' => ['domain' => 'example.com', 'documentroot' => '/home/cpuser/public_html'], 'addon_domains' => [], 'sub_domains' => [], 'parked_domains' => []]),
        ], $overrides);
        Process::fake(function (PendingProcess $process) use ($answers) {
            $words = array_values(array_filter(array_slice(array_values((array) $process->command), 1), fn (string $word) => ! str_starts_with($word, '--')));
            $key = $words[0].' '.$words[1];
            $this->calls[$key] = ($this->calls[$key] ?? 0) + 1;
            $this->callList[] = $key;

            return Process::result(output: json_encode($answers[$key] ?? $this->ok()));
        });
    }

    /** @var list<string> */
    private array $callList = [];

    private function mockConnection(): void
    {
        $this->partialMock(Installer::class, function ($mock): void {
            $mock->shouldReceive('connect')->andReturnUsing(fn (array $data) => DB::connection());
        });
    }

    public function test_it_provisions_the_account_installs_and_schedules_the_cron(): void
    {
        $this->cpanel();
        $this->mockConnection();

        $this->artisan('perysee:install', ['--cpanel' => true, '--domain' => 'stats.example.com', '--admin-name' => 'Owner', '--admin-email' => 'owner@example.com', '--no-interaction' => true])
            ->expectsOutputToContain('Perysee is installed. Sign in at https://stats.example.com/login')
            ->doesntExpectOutputToContain('Strong-password-123!')
            ->assertSuccessful();

        $values = app(EnvironmentFile::class)->values();
        $this->assertSame('https://stats.example.com', $values['APP_URL']);
        $this->assertStringStartsWith('cpuser_', $values['DB_USERNAME']);
        $this->assertSame('localhost', $values['DB_HOST']);
        $this->assertTrue(User::firstOrFail()->is_admin);
        $this->assertTrue(app(InstallationState::class)->isLocked());
        $this->assertSame(1, $this->calls['SubDomain addsubdomain']);
        $this->assertSame(1, $this->calls['Cron add_line']);
        $this->assertLessThan(array_search('Cron add_line', $this->callList), array_search('Mysql set_privileges_on_database', $this->callList));
    }

    public function test_a_failure_after_provisioning_removes_what_was_created_and_keeps_the_instance_installable(): void
    {
        $this->cpanel();
        $this->partialMock(Installer::class, function ($mock): void {
            $mock->shouldReceive('connect')->andThrow(ValidationException::withMessages(['database' => 'Unable to connect to MariaDB.']));
        });

        $this->artisan('perysee:install', ['--cpanel' => true, '--domain' => 'stats.example.com', '--admin-name' => 'Owner', '--admin-email' => 'owner@example.com', '--no-interaction' => true])
            ->assertFailed();

        $this->assertSame(1, $this->calls['SubDomain delsubdomain']);
        $this->assertSame(1, $this->calls['Mysql delete_user']);
        $this->assertSame(1, $this->calls['Mysql delete_database']);
        $this->assertArrayNotHasKey('Cron add_line', $this->calls);
        $this->assertDatabaseCount('users', 0);
        $this->assertFalse(app(InstallationState::class)->isLocked());
    }

    public function test_the_administrator_password_is_checked_before_anything_is_created_on_the_account(): void
    {
        $this->cpanel();
        putenv('PERYSEE_ADMIN_PASSWORD=short');

        $this->artisan('perysee:install', ['--cpanel' => true, '--domain' => 'stats.example.com', '--admin-name' => 'Owner', '--admin-email' => 'owner@example.com', '--no-interaction' => true])
            ->assertFailed();

        $this->assertSame([], $this->callList);
    }

    public function test_the_domain_is_required(): void
    {
        $this->cpanel();

        $this->artisan('perysee:install', ['--cpanel' => true, '--admin-name' => 'Owner', '--admin-email' => 'owner@example.com', '--no-interaction' => true])
            ->assertFailed();

        $this->assertSame([], $this->callList);
    }

    public function test_a_server_without_uapi_is_told_to_use_the_manual_mode(): void
    {
        $path = getenv('PATH');
        putenv('PATH=/nonexistent');
        $this->beforeApplicationDestroyed(fn () => putenv('PATH='.$path));
        $this->app->instance(UapiClient::class, new UapiClient);
        $this->cpanel();

        $this->artisan('perysee:install', ['--cpanel' => true, '--domain' => 'stats.example.com', '--admin-name' => 'Owner', '--admin-email' => 'owner@example.com', '--no-interaction' => true])
            ->expectsOutputToContain('cPanel')
            ->assertFailed();

        $this->assertSame([], $this->callList);
    }
}
