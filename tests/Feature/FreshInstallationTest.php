<?php

namespace Tests\Feature;

use App\Installation\InstallationState;
use App\Installation\Installer;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FreshInstallationTest extends TestCase
{
    public function test_a_completely_empty_database_can_be_installed_and_used(): void
    {
        $connection = DB::connection();
        $this->assertContains($connection->getDatabaseName(), [':memory:', 'perysee_test'], 'Only a dedicated test database may be reset.');
        $connection->getSchemaBuilder()->dropAllTables();
        RefreshDatabaseState::$migrated = false;
        if ($connection->getDriverName() === 'sqlite') {
            $this->partialMock(Installer::class, fn ($mock) => $mock->shouldReceive('connect')->andReturnUsing(fn () => $connection));
        }
        try {
            $this->get('/install')->assertOk();
            $this->post('/install/unlock', ['installation_secret' => trim(file_get_contents(app(InstallationState::class)->secretPath()))])->assertRedirect('/install');
            $settings = $connection->getConfig();
            $this->post('/install/database', [
                'db_host' => $settings['host'] ?? '127.0.0.1', 'db_port' => $settings['port'] ?? 13306,
                'db_database' => $connection->getDriverName() === 'sqlite' ? 'perysee_test' : $settings['database'],
                'db_username' => $settings['username'] ?? 'perysee_test', 'db_password' => $settings['password'] ?? 'test-password',
            ])->assertSessionHasNoErrors()->assertRedirect('/install');
            $this->post('/install/administrator', ['name' => 'Test owner', 'email' => 'owner@example.test', 'password' => 'Test-password-123!', 'password_confirmation' => 'Test-password-123!'])->assertSessionHasNoErrors()->assertRedirect('/login');
            $this->post('/login', ['email' => 'owner@example.test', 'password' => 'Test-password-123!'])->assertRedirect('/sites');
            $this->get('/sites')->assertOk()->assertInertia(fn (Assert $page) => $page->where('screen', 'sites.index')->has('websites.data', 0));
            $this->post('/sites', ['name' => 'First site', 'domain' => 'example.com', 'allowed_domains' => '', 'timezone' => 'Europe/Paris'])->assertSessionHasNoErrors()->assertRedirect();
            $website = Website::firstOrFail();
            $this->get('/sites/'.$website->id)->assertOk()->assertSee('First site');
            $this->put('/sites/'.$website->id, ['name' => 'Updated site', 'domain' => 'example.com', 'allowed_domains' => '', 'timezone' => 'UTC'])->assertSessionHasNoErrors();
            $this->assertSame('Updated site', $website->fresh()->name);
            $this->assertTrue(User::firstOrFail()->is_admin);
            $this->post('/logout')->assertRedirect('/login');
            $this->get('/install')->assertStatus(409);
        } finally {
            $connection->getSchemaBuilder()->dropAllTables();
            RefreshDatabaseState::$migrated = false;
        }
    }

    public function test_migrations_resume_after_some_tables_were_already_created(): void
    {
        $connection = DB::connection();
        $this->assertContains($connection->getDatabaseName(), [':memory:', 'perysee_test']);
        $connection->getSchemaBuilder()->dropAllTables();
        RefreshDatabaseState::$migrated = false;
        try {
            $this->artisan('migrate', ['--force' => true])->assertSuccessful();
            $schema = $connection->getSchemaBuilder();
            $schema->drop('sessions');
            $schema->drop('site_members');
            $connection->table('migrations')->where('migration', '0001_01_01_000000_create_users_table')->delete();
            $connection->table('migrations')->where('migration', 'like', '%create_websites_and_site_members_tables')->delete();
            $this->artisan('migrate', ['--force' => true])->assertSuccessful();
            $this->assertTrue($schema->hasTable('sessions'));
            $this->assertTrue($schema->hasTable('site_members'));
            $this->assertTrue($schema->hasTable('websites'));
        } finally {
            $connection->getSchemaBuilder()->dropAllTables();
            RefreshDatabaseState::$migrated = false;
        }
    }
}
