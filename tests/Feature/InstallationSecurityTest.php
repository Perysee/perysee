<?php

namespace Tests\Feature;

use App\Installation\Diagnostics;
use App\Installation\EnvironmentFile;
use App\Installation\InstallationState;
use Illuminate\Http\Request;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InstallationSecurityTest extends TestCase
{
    public function test_installer_boots_without_a_key_or_reachable_database(): void
    {
        config(['app.key' => null, 'database.default' => 'mariadb', 'database.connections.mariadb.host' => '127.0.0.1', 'database.connections.mariadb.port' => 1]);
        $this->app->forgetInstance('encrypter');
        $this->get('/install')->assertOk()->assertInertia(fn (Assert $page) => $page->where('screen', 'install')->where('authorized', false)->missing('databaseDefaults')->where('auth', null));
        $key = app(EnvironmentFile::class)->values()['APP_KEY'];
        $this->assertSame(32, strlen(base64_decode(substr($key, 7))));
        $this->get('/install')->assertOk();
        $this->assertSame($key, app(EnvironmentFile::class)->values()['APP_KEY']);
    }

    public function test_a_locked_instance_never_generates_a_replacement_key(): void
    {
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
        config(['app.key' => null]);
        $this->get('/login')->assertStatus(503);
        $this->assertFileDoesNotExist(app(EnvironmentFile::class)->path());
    }

    public function test_production_diagnostics_reject_http_debug_mode_and_wrong_docroot(): void
    {
        $this->app->instance('env', 'production');
        config(['app.debug' => true]);
        $request = Request::create('http://example.com/install', server: ['DOCUMENT_ROOT' => base_path(), 'SCRIPT_FILENAME' => public_path('index.php')]);
        $checks = collect(app(Diagnostics::class)->checks($request))->keyBy('label');
        $this->assertFalse($checks['HTTPS (required in production)']['ok']);
        $this->assertFalse($checks['Debug mode disabled in production']['ok']);
        $this->assertFalse($checks['Document root points to public/']['ok']);
        $this->assertFalse(app(Diagnostics::class)->passes($request));
    }

    public function test_configuration_rejects_newline_injection(): void
    {
        $this->expectException(\RuntimeException::class);
        app(EnvironmentFile::class)->write(['DB_PASSWORD' => "secret\nAPP_DEBUG=true"]);
    }
}
