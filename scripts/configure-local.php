<?php

declare(strict_types=1);
use App\Installation\EnvironmentFile;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__).'/vendor/autoload.php';

$root = dirname(__DIR__);
$databaseEnv = $root.'/infra/mariadb/.env';

if (! is_file($databaseEnv)) {
    fwrite(STDERR, "Run bash scripts/mariadb.sh up first.\n");
    exit(1);
}

$database = Dotenv\Dotenv::parse(file_get_contents($databaseEnv));
$path = $root.'/.env';
if (! is_file($path)) {
    $temporary = tempnam($root, '.local-env-');
    chmod($temporary, 0600);
    file_put_contents($temporary, file_get_contents($root.'/.env.example'));
    rename($temporary, $path);
}

$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['perysee.environment_path' => $path]);
$environment = app(EnvironmentFile::class);
$existing = $environment->values();
$updates = [];
foreach (['APP_KEY' => 'base64:'.base64_encode(random_bytes(32)), 'DB_PASSWORD' => $database['MARIADB_PASSWORD']] as $key => $value) {
    if (($existing[$key] ?? '') === '') {
        $updates[$key] = $value;
    }
}
$environment->write($updates);
echo "Local environment configured. Existing secrets preserved. No secrets are displayed.\n";
