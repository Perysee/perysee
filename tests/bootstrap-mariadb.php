<?php

require dirname(__DIR__).'/vendor/autoload.php';

$path = dirname(__DIR__).'/.env.testing';
if (! is_file($path)) {
    throw new RuntimeException('Create .env.testing using php scripts/configure-testing.php first.');
}
$values = Dotenv\Dotenv::parse(file_get_contents($path));
if (($values['APP_ENV'] ?? '') !== 'testing' || ($values['DB_CONNECTION'] ?? '') !== 'mariadb' || ($values['DB_DATABASE'] ?? '') !== 'perysee_test' || ($values['DB_USERNAME'] ?? '') !== 'perysee_test' || ! empty($values['DB_URL'])) {
    throw new RuntimeException('Refusing destructive tests: only the dedicated perysee_test database and user are allowed.');
}
$local = is_file(dirname(__DIR__).'/.env') ? Dotenv\Dotenv::parse(file_get_contents(dirname(__DIR__).'/.env')) : [];
if (($local['DB_DATABASE'] ?? '') === $values['DB_DATABASE']) {
    throw new RuntimeException('Refusing tests: the development database must differ from the test database.');
}
if (is_file(dirname(__DIR__).'/bootstrap/cache/config.php')) {
    throw new RuntimeException('Remove the configuration cache before database tests.');
}
foreach ($values as $key => $value) {
    $_ENV[$key] = $_SERVER[$key] = $value;
    putenv($key.'='.$value);
}
$_ENV['DB_URL'] = $_SERVER['DB_URL'] = '';
putenv('DB_URL=');
