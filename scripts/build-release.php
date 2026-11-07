<?php

declare(strict_types=1);

/** Build a production-only archive without copying installation state. */
function buildRelease(array $options): void
{
    $source = realpath($options['source'] ?? dirname(__DIR__));
    $output = $options['output'] ?? dirname(__DIR__).'/dist';
    $version = $options['version'] ?? '';
    $repository = $options['repository'] ?? '';
    if (! $source || ! preg_match('/^v\d+\.\d+\.\d+(?:-[a-zA-Z0-9.-]+)?$/D', $version) || ! preg_match('/^[a-zA-Z0-9_.-]+\/[a-zA-Z0-9_.-]+$/D', $repository)) {
        throw new RuntimeException('Provide --version vX.Y.Z and --repository OWNER/REPO (optional --source and --output).');
    }
    if (! class_exists(ZipArchive::class)) {
        throw new RuntimeException('The build requires the PHP zip extension.');
    }
    $configured = is_file($source.'/config/perysee.php') && preg_match("/'version'\\s*=>\\s*'([^']+)'/", file_get_contents($source.'/config/perysee.php'), $match) ? $match[1] : null;
    if ($configured !== ltrim($version, 'v')) {
        throw new RuntimeException('config/perysee.php declares version '.($configured ?? '(none)').' but the release is '.$version.'. Bump the version in config/perysee.php first: the application compares it with published releases.');
    }
    $installed = json_decode(file_get_contents($source.'/vendor/composer/installed.json'), true, flags: JSON_THROW_ON_ERROR);
    if (($installed['dev'] ?? true) !== false) {
        throw new RuntimeException('Install production dependencies with composer install --no-dev first.');
    }
    foreach (['LICENSE', 'LICENSING.md', 'MODULE-EXCEPTION.md', 'vendor/autoload.php', 'vendor/composer/platform_check.php', 'public/index.php', 'public/.htaccess', 'public/build/manifest.json', 'public/tracker/lueur.v1.js', 'public/tracker/lueur.v1.js.gz'] as $file) {
        if (! is_file($source.'/'.$file)) {
            throw new RuntimeException('Missing release file or asset: '.$file);
        }
    }
    $manifest = json_decode(file_get_contents($source.'/public/build/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    if (! $manifest) {
        throw new RuntimeException('Empty asset manifest. Run npm run build first.');
    }
    foreach ($manifest as $asset) {
        foreach (array_merge([$asset['file']], $asset['css'] ?? [], $asset['assets'] ?? []) as $file) {
            if (str_contains($file, '..') || ! is_file($source.'/public/build/'.$file)) {
                throw new RuntimeException('Missing compiled asset: '.$file);
            }
        }
    }
    if (! is_dir($source.'/public/fonts') || count(glob($source.'/public/fonts/*') ?: []) === 0) {
        throw new RuntimeException('Missing bundled font assets.');
    }
    if (! is_dir($output) && ! mkdir($output, 0755, true)) {
        throw new RuntimeException('Cannot create output directory.');
    }
    $geoManifest = json_decode(file_get_contents($source.'/resources/geoip/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    if (! hash_equals($geoManifest['sha256'], hash_file('sha256', $source.'/resources/geoip/country.mmdb'))) {
        throw new RuntimeException('Bundled country database checksum mismatch.');
    }
    $zip = new ZipArchive;
    if ($zip->open($output.'/perysee.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Cannot create release ZIP.');
    }
    try {
        foreach (['app', 'bootstrap', 'config', 'database/migrations', 'resources', 'routes', 'vendor', 'public/build', 'public/fonts', 'public/tracker', 'public/brands', 'public/maps'] as $directory) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source.'/'.$directory, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $entry) {
                $relative = substr($entry->getPathname(), strlen($source) + 1);
                if (str_starts_with($relative, 'bootstrap/cache/') || $entry->getFilename() === '.DS_Store') {
                    continue;
                }
                if ($entry->isLink() || ! $entry->isFile()) {
                    throw new RuntimeException('Release source contains a symlink or special file: '.$relative);
                }
                if (! $zip->addFile($entry->getPathname(), 'perysee/'.$relative)) {
                    throw new RuntimeException('Cannot archive '.$relative);
                }
            }
        }
        foreach (['artisan', 'composer.json', 'composer.lock', 'README.md', 'LICENSE', 'LICENSING.md', 'MODULE-EXCEPTION.md', 'public/index.php', 'public/.htaccess', 'public/favicon.ico', 'public/robots.txt', 'public/logo.svg', 'public/logo-dark.svg', 'public/favicon.svg', 'public/color-mode.js', 'public/site-icon.svg'] as $file) {
            if (is_file($source.'/'.$file) && ! is_link($source.'/'.$file)) {
                $zip->addFile($source.'/'.$file, 'perysee/'.$file);
            }
        }
        foreach (['bootstrap/cache', 'storage/app/private', 'storage/app/public', 'storage/framework/cache/data', 'storage/framework/cache/locks', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs'] as $directory) {
            $zip->addEmptyDir('perysee/'.$directory);
        }
        $zip->addFromString('perysee/release.json', json_encode(['version' => $version, 'repository' => $repository], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    } finally {
        if (! $zip->close()) {
            throw new RuntimeException('Cannot finish release ZIP.');
        }
    }
    $installer = file_get_contents(__DIR__.'/install.php');
    $installer = str_replace(["const PERYSEE_REPOSITORY = 'Perysee/perysee';", "const PERYSEE_RELEASE = 'latest';"],
        ['const PERYSEE_REPOSITORY = '.var_export($repository, true).';', 'const PERYSEE_RELEASE = '.var_export($version, true).';'], $installer);
    file_put_contents($output.'/install.php', $installer);
    $bootstrap = str_replace('https://github.com/Perysee/perysee/releases/latest/download',
        'https://get.perysee.com/releases/'.$version, file_get_contents(__DIR__.'/install.sh'));
    file_put_contents($output.'/install.sh', $bootstrap);
    // The Docker installer builds its image from these files and the ZIP; they are verified like the ZIP itself.
    foreach (['Dockerfile', 'compose.yaml', 'docker-entrypoint.sh'] as $file) {
        if (! is_file(dirname(__DIR__).'/docker/'.$file) || ! copy(dirname(__DIR__).'/docker/'.$file, $output.'/'.$file)) {
            throw new RuntimeException('Missing Docker file: docker/'.$file);
        }
    }
    $checksums = '';
    foreach (['perysee.zip', 'install.php', 'install.sh', 'Dockerfile', 'compose.yaml', 'docker-entrypoint.sh'] as $file) {
        $checksums .= hash_file('sha256', $output.'/'.$file).'  '.$file."\n";
    }
    file_put_contents($output.'/SHA256SUMS', $checksums);
    echo "Release {$version}: {$output}/perysee.zip, install.php, install.sh, Dockerfile, compose.yaml, docker-entrypoint.sh, SHA256SUMS\n";
}

try {
    buildRelease(getopt('', ['source:', 'output:', 'version:', 'repository:']));
} catch (Throwable $exception) {
    fwrite(STDERR, 'Build failed: '.$exception->getMessage()."\n");
    exit(1);
}
