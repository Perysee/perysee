<?php

declare(strict_types=1);

const PERYSEE_REPOSITORY = 'mattrbdr/perysee';
const PERYSEE_RELEASE = 'latest';

function installationPrompt(string $label, string $default = ''): string
{
    echo $label.($default !== '' ? " [{$default}]" : '').': ';
    $answer = fgets(STDIN);
    if ($answer === false) {
        throw new RuntimeException('No interactive input. Download this script first, then run php install.php; do not pipe it to PHP.');
    }

    return trim($answer) ?: $default;
}

/** Download only through verified HTTPS, without sending tokens to redirect hosts. */
function installationDownload(string $url, string $destination, int $limit, string $accept = 'application/octet-stream'): void
{
    $file = fopen($destination, 'wb');
    if ($file === false) {
        throw new RuntimeException('Cannot write temporary download.');
    }
    $curl = curl_init($url);
    $headers = ['Accept: '.$accept];
    $token = getenv('GH_TOKEN') ?: getenv('GITHUB_TOKEN');
    if ($token && parse_url($url, PHP_URL_HOST) === 'api.github.com') {
        $headers[] = 'Authorization: Bearer '.$token;
    }
    $bytes = 0;
    curl_setopt_array($curl, [
        CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_CONNECTTIMEOUT => 20, CURLOPT_TIMEOUT => 300, CURLOPT_FAILONERROR => true,
        CURLOPT_USERAGENT => 'Perysee-Installer', CURLOPT_HTTPHEADER => $headers,
        CURLOPT_WRITEFUNCTION => static function ($handle, string $data) use ($file, $limit, &$bytes): int {
            $bytes += strlen($data);
            if ($bytes > $limit) {
                return 0;
            }

            return fwrite($file, $data) ?: 0;
        },
    ]);
    try {
        if (curl_exec($curl) === false) {
            throw new RuntimeException('GitHub download failed (HTTP '.curl_getinfo($curl, CURLINFO_RESPONSE_CODE).'). Check connectivity, the published release, and GH_TOKEN access for a private repository.');
        }
    } finally {
        fclose($file);
    }
}

function installationRemoveDirectory(string $directory): void
{
    if (! is_dir($directory) || is_link($directory)) {
        return;
    }
    foreach (new FilesystemIterator($directory) as $entry) {
        if ($entry->isDir() && ! $entry->isLink()) {
            installationRemoveDirectory($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    rmdir($directory);
}

function installationDestination(string $path): string
{
    if (str_starts_with($path, '~/')) {
        $home = getenv('HOME');
        if (! $home) {
            throw new RuntimeException('Use an absolute path; HOME is unavailable.');
        }
        $path = $home.substr($path, 1);
    }
    $path = rtrim($path, '/');
    if ($path === '' || preg_match('/[\x00-\x1f]/', $path) || is_link($path) || is_file($path)) {
        throw new RuntimeException('Choose a new or empty application directory.');
    }
    if (is_dir($path) && iterator_count(new FilesystemIterator($path)) > 0) {
        throw new RuntimeException('Destination is not empty. Existing installations are never overwritten.');
    }
    $parent = realpath(dirname($path));
    if ($parent === false || ! is_writable($parent)) {
        throw new RuntimeException('The destination parent must exist and be writable.');
    }

    return $parent.'/'.basename($path);
}

/** Validate all entries before extracting into a private sibling directory. */
function installationExtract(string $archive, string $directory): void
{
    $zip = new ZipArchive;
    if ($zip->open($archive, ZipArchive::CHECKCONS) !== true) {
        throw new RuntimeException('Cannot open release ZIP.');
    }
    try {
        $size = 0;
        $seen = [];
        if ($zip->numFiles > 50000) {
            throw new RuntimeException('Release ZIP has too many entries.');
        }
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entry = $zip->statIndex($index);
            $name = $entry['name'];
            $parts = explode('/', rtrim($name, '/'));
            if (! str_starts_with($name, 'perysee/') || in_array('..', $parts, true) || in_array('.', $parts, true) || in_array('', $parts, true) || preg_match('/[\\\\\x00-\x1f:]/', $name) || isset($seen[$name])) {
                throw new RuntimeException('Unsafe archive path: '.$name);
            }
            $seen[$name] = true;
            $zip->getExternalAttributesIndex($index, $system, $attributes);
            $type = ($attributes >> 16) & 0170000;
            if ($type !== 0 && $type !== 0100000 && $type !== 0040000) {
                throw new RuntimeException('Unsafe archive symlink or special file.');
            }
            $size += $entry['size'];
            if ($size > 536870912) {
                throw new RuntimeException('Release ZIP exceeds the 512 MiB extracted-size limit.');
            }
        }
        if (! $zip->extractTo($directory)) {
            throw new RuntimeException('Cannot extract ZIP. Check disk quota.');
        }
    } finally {
        $zip->close();
    }
    foreach (['artisan', 'public/index.php', 'public/build/manifest.json', 'vendor/autoload.php', 'vendor/composer/platform_check.php', 'release.json'] as $file) {
        if (! is_file($directory.'/perysee/'.$file)) {
            throw new RuntimeException('Incomplete release: missing '.$file);
        }
    }
    foreach (['.env', 'public/hot', 'bootstrap/cache/config.php', 'storage/app/private/installation/installed', 'storage/app/private/installation/secret'] as $file) {
        if (file_exists($directory.'/perysee/'.$file)) {
            throw new RuntimeException('Release contains private installation state or development cache.');
        }
    }
    require $directory.'/perysee/vendor/composer/platform_check.php';
}

function installPerysee(array $options): void
{
    if (isset($options['help'])) {
        echo "Perysee installer (PHP 8.3+, curl and zip).\nphp install.php [--target /path/perysee --url https://stats.example.com --yes]\nOffline: add --archive /path/perysee.zip --checksums /path/SHA256SUMS\nPrivate GitHub: set GH_TOKEN in the environment; never pass it as an argument.\n";

        return;
    }
    $extensions = ['ctype', 'curl', 'dom', 'fileinfo', 'filter', 'hash', 'mbstring', 'openssl', 'pcre', 'pdo', 'pdo_mysql', 'session', 'tokenizer', 'xml', 'zip'];
    $missing = array_filter($extensions, fn (string $extension): bool => ! extension_loaded($extension));
    if (PHP_VERSION_ID < 80300 || $missing) {
        throw new RuntimeException('PHP 8.3+ is required. Missing extensions: '.implode(', ', $missing));
    }
    if (isset($options['yes']) && (! isset($options['target']) || ! isset($options['url']))) {
        throw new RuntimeException('--yes requires both --target and --url.');
    }
    echo 'Perysee '.PERYSEE_RELEASE." — first installation\n";
    $target = installationDestination($options['target'] ?? installationPrompt('Application directory', (getenv('HOME') ?: getcwd()).'/perysee'));
    $url = rtrim($options['url'] ?? installationPrompt('Public HTTPS URL (domain or subdomain)'), '/');
    $parsed = parse_url($url);
    if (! filter_var($url, FILTER_VALIDATE_URL) || ($parsed['scheme'] ?? '') !== 'https' || empty($parsed['host']) || isset($parsed['user'], $parsed['pass']) || isset($parsed['user']) || isset($parsed['query']) || isset($parsed['fragment']) || ! empty($parsed['path'])) {
        throw new RuntimeException('Provide an HTTPS origin without a path, credentials, query or fragment.');
    }
    echo "Destination: {$target}\nRequired document root: {$target}/public\n";
    if (! isset($options['yes']) && strtolower(installationPrompt('Download and install here? Type yes', 'no')) !== 'yes') {
        echo "Installation cancelled.\n";

        return;
    }
    $staging = dirname($target).'/.perysee-install-'.bin2hex(random_bytes(8));
    if (! mkdir($staging, 0700)) {
        throw new RuntimeException('Cannot create private staging directory.');
    }
    try {
        if (isset($options['archive']) !== isset($options['checksums'])) {
            throw new RuntimeException('Provide both --archive and --checksums for offline installation.');
        }
        $archive = $options['archive'] ?? $staging.'/perysee.zip';
        $checksums = $options['checksums'] ?? $staging.'/SHA256SUMS';
        if (! isset($options['archive'])) {
            $endpoint = PERYSEE_RELEASE === 'latest' ? 'latest' : 'tags/'.rawurlencode(PERYSEE_RELEASE);
            $api = 'https://api.github.com/repos/'.PERYSEE_REPOSITORY.'/releases/';
            installationDownload($api.$endpoint, $staging.'/release.json', 2097152, 'application/vnd.github+json');
            $release = json_decode(file_get_contents($staging.'/release.json'), true, flags: JSON_THROW_ON_ERROR);
            if ($release['draft'] ?? true) {
                throw new RuntimeException('No published release is available.');
            }
            foreach (['perysee.zip' => $archive, 'SHA256SUMS' => $checksums] as $name => $destination) {
                $assets = array_values(array_filter($release['assets'] ?? [], fn (array $asset): bool => ($asset['name'] ?? '') === $name));
                if (count($assets) !== 1 || ! is_int($assets[0]['id'] ?? null)) {
                    throw new RuntimeException('Release asset is missing: '.$name);
                }
                installationDownload($api.'assets/'.$assets[0]['id'], $destination, $name === 'perysee.zip' ? 134217728 : 65536);
            }
        }
        $manifest = is_file($checksums) ? file_get_contents($checksums) : '';
        if (! preg_match('/^([a-f0-9]{64})  perysee\.zip\r?$/m', $manifest, $match) || ! is_file($archive) || ! hash_equals($match[1], hash_file('sha256', $archive))) {
            throw new RuntimeException('Release checksum verification failed. Nothing was installed.');
        }
        installationExtract($archive, $staging.'/extracted');
        $root = $staging.'/extracted/perysee';
        $metadata = json_decode(file_get_contents($root.'/release.json'), true, flags: JSON_THROW_ON_ERROR);
        if (($metadata['repository'] ?? '') !== PERYSEE_REPOSITORY || (PERYSEE_RELEASE !== 'latest' && ($metadata['version'] ?? '') !== PERYSEE_RELEASE)) {
            throw new RuntimeException('Archive repository or version does not match this installer.');
        }
        foreach (['', '/storage', '/storage/framework', '/storage/framework/sessions', '/storage/framework/views', '/bootstrap/cache'] as $path) {
            if (! is_writable($root.$path)) {
                throw new RuntimeException('Extracted application is not writable: '.$path);
            }
        }
        installationDestination($target);
        if (is_dir($target) && ! rmdir($target)) {
            throw new RuntimeException('Cannot replace the empty destination directory.');
        }
        if (! rename($root, $target)) {
            throw new RuntimeException('Cannot move staged application to destination.');
        }
        echo "\nPerysee files installed successfully.\n1. Set your domain document root to: {$target}/public\n2. Enable HTTPS and select compatible web PHP (CLI checks do not configure web PHP).\n3. Create an empty MariaDB 11.4.x database and user.\n4. Open: {$url}/install\n5. Retrieve storage/app/private/installation/secret after the first visit.\n6. Configure the retention cron after completing the browser installer.\n";
    } finally {
        installationRemoveDirectory($staging);
    }
}

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
try {
    installPerysee(getopt('', ['help', 'target:', 'url:', 'yes', 'archive:', 'checksums:']));
} catch (Throwable $exception) {
    fwrite(STDERR, 'Installation failed: '.$exception->getMessage()."\n");
    exit(1);
}
