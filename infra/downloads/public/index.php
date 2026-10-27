<?php

declare(strict_types=1);
use PeryseeDownloads\Gateway;

ini_set('display_errors', '0');
ini_set('zlib.output_compression', '0');
header_remove('X-Powered-By');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    $privateRoot = dirname(__DIR__);
    $publicRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? __DIR__);
    $configPath = realpath($privateRoot.'/config.php');
    if (! $configPath || ! $publicRoot || $configPath === $publicRoot || str_starts_with($configPath, $publicRoot.DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Private download configuration unavailable.');
    }
    require_once $privateRoot.'/Gateway.php';
    $config = require $configPath;
    $config['public_root'] = $publicRoot;
    set_time_limit(300);
    $response = (new Gateway($config))->handle($method, $_SERVER['REQUEST_URI'] ?? '/');
    $stream = $response['file'] ? fopen($response['file'], 'rb') : null;
    if ($stream === false) {
        throw new RuntimeException('Cached download unavailable.');
    }
} catch (Throwable) {
    $response = ['status' => 503, 'headers' => ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff', 'Retry-After' => '60'], 'body' => "Perysee downloads are temporarily unavailable. Please try again later.\n"];
    $stream = null;
}

http_response_code($response['status']);
foreach ($response['headers'] as $name => $value) {
    header($name.': '.$value);
}
if ($method !== 'HEAD') {
    if (is_resource($stream)) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        fpassthru($stream);
    } else {
        echo $response['body'];
    }
}
if (is_resource($stream)) {
    fclose($stream);
}
