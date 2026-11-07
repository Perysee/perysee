<?php

use App\Models\User;
use App\Support\TwoFactor;
use Illuminate\Contracts\Http\Kernel;

// usage: two-factor-child.php <user id> <code>
require dirname(__DIR__).'/bootstrap-mariadb.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['session.driver' => 'array', 'cache.default' => 'array']);
$user = User::findOrFail((int) $argv[1]);
fwrite(STDOUT, "READY\n");
fflush(STDOUT);
fwrite(STDOUT, json_encode(['method' => app(TwoFactor::class)->verifyChallenge($user, $argv[2])], JSON_THROW_ON_ERROR));
