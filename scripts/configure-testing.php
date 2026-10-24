<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

$root = dirname(__DIR__);
$credentials = $root.'/infra/mariadb/.env.testing';
if (! is_file($credentials)) {
    fwrite(STDERR, "Run bash scripts/mariadb.sh test-db first.\n");
    exit(1);
}
$database = Dotenv\Dotenv::parse(file_get_contents($credentials));
$path = $root.'/.env.testing';
if (is_file($path)) {
    fwrite(STDERR, "Existing .env.testing preserved. Remove it manually if test credentials need replacing.\n");
    exit(0);
}
$contents = "APP_NAME=Perysee\nAPP_ENV=testing\nAPP_KEY=base64:".base64_encode(random_bytes(32))."\nAPP_DEBUG=true\nAPP_LOCALE=en\nDB_CONNECTION=mariadb\nDB_HOST=127.0.0.1\nDB_PORT=13306\nDB_DATABASE=perysee_test\nDB_USERNAME=perysee_test\nDB_PASSWORD=".$database['MARIADB_TEST_PASSWORD']."\nDB_URL=\nCACHE_STORE=array\nSESSION_DRIVER=array\nQUEUE_CONNECTION=sync\nMAIL_MAILER=array\nBCRYPT_ROUNDS=4\n";
$handle = fopen($path, 'x');
chmod($path, 0600);
fwrite($handle, $contents);
fclose($handle);
echo "Dedicated test configuration created. No secrets displayed.\n";
