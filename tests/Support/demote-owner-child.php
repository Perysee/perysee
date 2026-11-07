<?php

use App\Installation\InstallationState;
use App\Models\User;
use App\Models\Website;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

require dirname(__DIR__).'/bootstrap-mariadb.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
config(['perysee.installation_path' => $argv[4], 'session.driver' => 'array', 'cache.default' => 'array']);
app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
$site = Website::findOrFail((int) $argv[1]);
$request = Request::create('/sites/'.$site->id.'/team/'.(int) $argv[3], 'PUT', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: json_encode(['role' => 'viewer'], JSON_THROW_ON_ERROR));
$app->instance('request', $request);
Auth::login(User::findOrFail((int) $argv[2]));
fwrite(STDOUT, "READY\n");
fflush(STDOUT);
$response = $kernel->handle($request);
fwrite(STDOUT, json_encode(['status' => $response->getStatusCode()], JSON_THROW_ON_ERROR));
$kernel->terminate($request, $response);
