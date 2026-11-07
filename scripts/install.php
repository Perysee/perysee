<?php

declare(strict_types=1);

const PERYSEE_REPOSITORY = 'Perysee/perysee';
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

/** The installation modes this installer can run. */
function installationModes(): array
{
    return ['manual'];
}

/** What to do once the files are in place: prepare the browser installer, run it in the terminal, or stop. */
function installationFinishModes(): array
{
    return ['browser', 'terminal', 'none'];
}

/**
 * Remove the staging directory even when the process is interrupted (Ctrl+C, hang-up), where `finally` does not run.
 */
function installationTrackStaging(?string $directory = null): void
{
    static $tracked = null;
    static $registered = false;
    if ($directory !== null) {
        $tracked = $directory;
    }
    if ($registered) {
        return;
    }
    $registered = true;
    register_shutdown_function(static function () use (&$tracked): void {
        if ($tracked !== null) {
            installationRemoveDirectory($tracked);
        }
    });
    if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
        pcntl_async_signals(true);
        foreach ([SIGINT, SIGTERM, SIGHUP] as $signal) {
            pcntl_signal($signal, static fn () => exit(130));
        }
    }
}

/** Run the installed application's artisan with this PHP; the output is captured unless $interactive is true. */
function installationArtisan(string $target, array $arguments, bool $interactive): array
{
    $command = array_merge([PHP_BINARY, $target.'/artisan'], $arguments);
    $descriptors = $interactive ? [0 => STDIN, 1 => STDOUT, 2 => STDERR] : [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($command, $descriptors, $pipes, $target);
    if (! is_resource($process)) {
        return [1, ''];
    }
    $output = '';
    if (! $interactive) {
        $output = (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
    }

    return [proc_close($process), $output];
}

/** The final instructions, either from the prepared browser link or the manual steps. */
function installationFinish(string $target, string $url, string $finish): void
{
    if ($finish === 'terminal') {
        echo "\nFinishing the installation in the terminal. The database must already exist; its password goes in PERYSEE_DB_PASSWORD.\n";
        [$status] = installationArtisan($target, ['perysee:install', '--url='.$url], true);
        if ($status !== 0) {
            throw new RuntimeException("perysee:install did not finish. Fix the reported problem and run: php {$target}/artisan perysee:install --url={$url}");
        }

        return;
    }
    if ($finish === 'browser') {
        [$status, $output] = installationArtisan($target, ['perysee:install', '--browser', '--url='.$url], false);
        if ($status === 0 && str_contains($output, '#secret=')) {
            echo "\nPerysee files installed successfully.\n1. Set your domain document root to: {$target}/public\n2. Enable HTTPS and select compatible web PHP (CLI checks do not configure web PHP).\n3. Create an empty MariaDB database (10.11, 11.4 or 11.8) and user.\n4. Finish in your browser with this private link; it unlocks the installer by itself:\n".trim($output)."\n5. Configure the retention cron after completing the browser installer.\n";

            return;
        }
        echo "\nThe browser link could not be prepared; follow the manual steps instead.\n";
    }
    echo "\nPerysee files installed successfully.\n1. Set your domain document root to: {$target}/public\n2. Enable HTTPS and select compatible web PHP (CLI checks do not configure web PHP).\n3. Create an empty MariaDB database (10.11, 11.4 or 11.8) and user.\n4. Open: {$url}/install\n5. Retrieve storage/app/private/installation/secret after the first visit.\n6. Configure the retention cron after completing the browser installer.\n";
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
        echo "Perysee installer (PHP 8.3+, curl and zip).\nphp install.php [--mode manual] [--target /path/perysee --url https://stats.example.com --yes]\nFinish: --finish browser (default; prints a private link that unlocks the browser installer), terminal (runs php artisan perysee:install) or none.\nOffline: add --archive /path/perysee.zip --checksums /path/SHA256SUMS\nPrivate GitHub: set GH_TOKEN in the environment; never pass it as an argument.\nAnother version: use its own address, https://get.perysee.com/releases/vX.Y.Z/install.sh\n";

        return;
    }
    $extensions = ['ctype', 'curl', 'dom', 'fileinfo', 'filter', 'hash', 'mbstring', 'openssl', 'pcre', 'pdo', 'pdo_mysql', 'session', 'tokenizer', 'xml', 'zip'];
    $missing = array_filter($extensions, fn (string $extension): bool => ! extension_loaded($extension));
    if (PHP_VERSION_ID < 80300 || $missing) {
        throw new RuntimeException('PHP 8.3+ is required. Missing extensions: '.implode(', ', $missing));
    }
    $mode = $options['mode'] ?? 'manual';
    if (! in_array($mode, installationModes(), true)) {
        throw new RuntimeException('Unknown installation mode "'.$mode.'". Available: '.implode(', ', installationModes()).'.');
    }
    $finish = $options['finish'] ?? 'browser';
    if (! in_array($finish, installationFinishModes(), true)) {
        throw new RuntimeException('--finish must be one of: '.implode(', ', installationFinishModes()).'.');
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
    installationTrackStaging($staging);
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
    } finally {
        installationRemoveDirectory($staging);
    }
    installationFinish($target, $url, $finish);
}

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
try {
    installPerysee(getopt('', ['help', 'mode:', 'finish:', 'target:', 'url:', 'yes', 'archive:', 'checksums:']));
} catch (Throwable $exception) {
    fwrite(STDERR, 'Installation failed: '.$exception->getMessage()."\n");
    exit(1);
}
