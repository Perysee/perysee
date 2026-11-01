<?php

declare(strict_types=1);

namespace App\Modules;

use Illuminate\Support\Facades\File;
use ZipArchive;

/**
 * Installs a module from a ZIP archive. Nothing in the archive is executed: its files are checked, written to a
 * temporary folder, and moved into the modules folder in one step. The module stays disabled until an
 * administrator enables it. The archive is not signed: it is code the administrator chose to trust.
 */
final class ModuleInstaller
{
    public const MAX_FILES = 2000;

    public const MAX_BYTES = 50 * 1024 * 1024;

    /** Extensions a module needs. Anything else, scripts and alternative PHP extensions included, is refused. */
    private const ALLOWED = ['php', 'json', 'js', 'css', 'md', 'txt', 'svg', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'ico', 'woff', 'woff2', 'stub'];

    public function __construct(private ModuleRegistry $registry) {}

    public static function unavailableReason(): ?string
    {
        if (! config('perysee.module_uploads')) {
            return 'Uploading modules is turned off on this instance (PERYSEE_MODULE_UPLOADS). Copy the module folder to the server instead.';
        }
        if (! class_exists(ZipArchive::class)) {
            return 'The PHP Zip extension is not available on this server.';
        }
        $path = rtrim((string) config('perysee.modules_path'), '/');
        $writable = is_dir($path) ? is_writable($path) : is_writable(dirname($path));

        return $writable ? null : "The modules folder is not writable: {$path}";
    }

    public function install(string $archive): Manifest
    {
        if (($reason = self::unavailableReason()) !== null) {
            throw new InvalidModuleBundle($reason);
        }
        $zip = new ZipArchive;
        if ($zip->open($archive, ZipArchive::RDONLY) !== true) {
            throw new InvalidModuleBundle('This file is not a valid ZIP archive.');
        }
        try {
            $entries = $this->entries($zip);
            $prefix = $this->root(array_keys($entries));
            $manifest = $this->manifest($zip, $prefix);
            if (! $manifest->isCompatibleWith(config('perysee.version'))) {
                throw new InvalidModuleBundle("Module {$manifest->id} requires Perysee {$manifest->core} or later.");
            }
            $base = rtrim((string) config('perysee.modules_path'), '/');
            File::ensureDirectoryExists($base, 0755);
            $destination = $base.'/'.$manifest->id;
            if (file_exists($destination)) {
                throw new InvalidModuleBundle("Module {$manifest->id} is already installed. Remove its folder first to install another version.");
            }
            $files = array_filter($entries, fn (array $entry, string $name): bool => str_starts_with($name, $prefix), ARRAY_FILTER_USE_BOTH);
            $this->checkTypes(array_keys($files), $prefix);
            $this->extract($zip, $files, $prefix, $base, $destination);
        } finally {
            $zip->close();
        }
        $this->registry->refresh();

        return $manifest;
    }

    /**
     * File entries of the archive, validated: no absolute path, no "..", no backslash, no link, within the limits.
     *
     * @return array<string, array{index: int, size: int}>
     */
    private function entries(ZipArchive $zip): array
    {
        $entries = [];
        $total = 0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);
            if ($name === '' || str_contains($name, "\0") || str_contains($name, '\\') || str_starts_with($name, '/') || preg_match('#^[A-Za-z]:#', $name) || in_array('..', explode('/', $name), true)) {
                throw new InvalidModuleBundle("The archive contains an unsafe path: {$name}");
            }
            $zip->getExternalAttributesIndex($index, $system, $attributes);
            if ($system === ZipArchive::OPSYS_UNIX && (($attributes >> 16) & 0170000) === 0120000) {
                throw new InvalidModuleBundle("The archive contains a link ({$name}), which is not allowed.");
            }
            if (str_ends_with($name, '/') || str_starts_with($name, '__MACOSX/') || str_starts_with(basename($name), '.')) {
                continue;
            }
            $size = (int) ($zip->statIndex($index)['size'] ?? 0);
            $total += $size;
            if (count($entries) >= self::MAX_FILES) {
                throw new InvalidModuleBundle('The archive contains too many files (limit '.self::MAX_FILES.').');
            }
            if ($total > self::MAX_BYTES) {
                throw new InvalidModuleBundle('The archive is too large once unpacked (limit '.intdiv(self::MAX_BYTES, 1048576).' MB).');
            }
            $entries[$name] = ['index' => $index, 'size' => $size];
        }

        return $entries;
    }

    /**
     * The folder inside the archive that holds module.json: the top level, or the only top-level folder.
     *
     * @param  list<string>  $names
     */
    private function root(array $names): string
    {
        if (in_array('module.json', $names, true)) {
            return '';
        }
        $folders = array_unique(array_map(fn (string $name): string => explode('/', $name)[0], array_filter($names, fn (string $name): bool => str_contains($name, '/'))));
        if (count($folders) === 1 && in_array($folders[array_key_first($folders)].'/module.json', $names, true)) {
            return $folders[array_key_first($folders)].'/';
        }

        throw new InvalidModuleBundle('Could not find a single module.json at the top of the archive.');
    }

    private function manifest(ZipArchive $zip, string $prefix): Manifest
    {
        $json = $zip->getFromName($prefix.'module.json', 65536);
        $data = $json === false ? null : json_decode($json, true);
        if (! is_array($data)) {
            throw new InvalidModuleBundle('module.json is missing or is not valid JSON.');
        }
        try {
            return Manifest::fromArray($data);
        } catch (InvalidManifest $exception) {
            throw new InvalidModuleBundle($exception->getMessage());
        }
    }

    /**
     * @param  list<string>  $names
     */
    private function checkTypes(array $names, string $prefix): void
    {
        foreach ($names as $name) {
            if (! in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::ALLOWED, true)) {
                throw new InvalidModuleBundle('The file type of '.substr($name, strlen($prefix)).' is not allowed in a module.');
            }
        }
    }

    /**
     * @param  array<string, array{index: int, size: int}>  $files
     */
    private function extract(ZipArchive $zip, array $files, string $prefix, string $base, string $destination): void
    {
        $temporary = $base.'/.installing-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($temporary, 0755);
        try {
            foreach ($files as $name => $entry) {
                $target = $temporary.'/'.substr($name, strlen($prefix));
                File::ensureDirectoryExists(dirname($target), 0755);
                $source = $zip->getStream($name);
                $output = fopen($target, 'wb');
                if ($source === false || $output === false) {
                    throw new InvalidModuleBundle("Could not unpack {$name}.");
                }
                $copied = stream_copy_to_stream($source, $output, $entry['size'] + 1);
                fclose($source);
                fclose($output);
                // The size in the archive header is a claim: the bytes actually read must match it.
                if ($copied !== $entry['size']) {
                    throw new InvalidModuleBundle("The archive is corrupt ({$name}).");
                }
            }
            if (! rename($temporary, $destination)) {
                throw new InvalidModuleBundle('Could not move the module into place.');
            }
        } finally {
            if (is_dir($temporary)) {
                File::deleteDirectory($temporary);
            }
        }
    }
}
