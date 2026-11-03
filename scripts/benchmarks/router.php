<?php

use App\Installation\InstallationState;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// Laboratory entry point only: never package or expose this directory on a host.
if (PHP_SAPI !== 'cli-server' || ! in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit;
}
require dirname(__DIR__, 2).'/tests/bootstrap-mariadb.php';
$payload = json_decode(file_get_contents('php://input'), true);
if (! is_array($payload) || ($payload['host'] ?? '') !== 'benchmark.invalid' || ($payload['mode'] ?? '') !== 'pageviews') {
    http_response_code(422);
    exit;
}
$mode = explode('?', $_SERVER['REQUEST_URI'])[0];
try {
    if ($mode === '/pdo') {
        $pdo = new PDO('mysql:host='.getenv('DB_HOST').';port='.getenv('DB_PORT').';dbname=perysee_test;charset=utf8mb4', 'perysee_test', getenv('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $select = $pdo->prepare('SELECT id FROM websites WHERE public_id = ? AND domain = ?');
        $select->execute([$payload['site'], 'benchmark.invalid']);
        $id = $select->fetchColumn();
        if (! $id) {
            throw new RuntimeException('Missing laboratory site.');
        }
        $pdo->prepare('INSERT INTO events (website_id, received_at, path, consent_given, tracking_mode) VALUES (?, UTC_TIMESTAMP(3), ?, ?, ?)')->execute([$id, $payload['path'], 1, 'pageviews']);
        header('X-Benchmark-Peak: '.memory_get_peak_usage(true));
        http_response_code(204);
    } else {
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $kernel = $app->make(Kernel::class);
        // The application provider inspects the current request while booting, so one must exist before the manual bootstrap.
        $app->instance('request', Request::create($mode, 'POST'));
        $kernel->bootstrap();
        config(['perysee.installation_path' => sys_get_temp_dir().'/perysee-benchmark-install', 'cache.default' => 'array']);
        if ($mode === '/minimal') {
            $site = DB::table('websites')->where('public_id', $payload['site'])->where('domain', 'benchmark.invalid')->firstOrFail();
            DB::table('events')->insert(['website_id' => $site->id, 'received_at' => now('UTC')->format('Y-m-d H:i:s.v'), 'path' => $payload['path'], 'consent_given' => true, 'tracking_mode' => 'pageviews']);
            header('X-Benchmark-Peak: '.memory_get_peak_usage(true));
            http_response_code(204);
        } elseif (in_array($mode, ['/full', '/full-no-geo'], true)) {
            if ($mode === '/full-no-geo') {
                config(['perysee.geoip_path' => '/missing/disabled-benchmark.mmdb']);
            }
            $state = app(InstallationState::class);
            if (! $state->isLocked()) {
                $state->exclusive(fn () => $state->complete());
            }
            $request = Request::create('/api/collect', 'POST', server: ['HTTP_ORIGIN' => 'https://benchmark.invalid', 'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '8.8.8.8'], content: json_encode($payload, JSON_THROW_ON_ERROR));
            $response = $kernel->handle($request);
            $response->headers->set('X-Benchmark-Peak', (string) memory_get_peak_usage(true));
            $response->send();
            $kernel->terminate($request, $response);
        } else {
            http_response_code(404);
        }
    }
} catch (Throwable $exception) {
    header('X-Benchmark-Error: '.get_class($exception));
    http_response_code(503);
}
