<?php

declare(strict_types=1);

namespace App\Updates;

use ZipArchive;

/**
 * Unpacks a release archive into a staging folder without executing anything. The rules mirror the installer
 * (scripts/install.php): one "perysee/" root, no unsafe path, no link, no duplicate, no local state, and the
 * files an application cannot start without.
 */
final class ArchiveExtractor
{
    public const MAX_ENTRIES = 50000;

    public const MAX_BYTES = 512 * 1024 * 1024;

    private const ROOT = 'perysee/';

    private const REQUIRED = ['artisan', 'config/perysee.php', 'public/index.php', 'public/build/manifest.json', 'vendor/autoload.php', 'vendor/composer/platform_check.php', 'release.json'];

    /** Local state of an instance: a release must never ship, and an update must never replace, any of it. */
    private const FORBIDDEN = ['.env', 'public/hot', 'bootstrap/cache/config.php', 'storage/app/private/installation/installed', 'storage/app/private/installation/secret'];

    /**
     * @return array{version: string, repository: string, entries: int, bytes: int}
     */
    public function extract(string $archive, string $destination): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new UpdateException('The PHP zip extension is required to read the release archive.');
        }
        $zip = new ZipArchive;
        if ($zip->open($archive, ZipArchive::CHECKCONS) !== true) {
            throw new UpdateException('The release archive is not a valid ZIP file.');
        }
        try {
            $entries = $this->entries($zip);
            $this->assertComplete(array_keys($entries));
            $this->write($zip, $entries, $destination);
        } finally {
            $zip->close();
        }
        $release = json_decode((string) file_get_contents($destination.'/release.json'), true);
        if (! is_array($release) || ! isset($release['version']) || ! is_string($release['version']) || ! Version::isValid($release['version'])) {
            throw new UpdateException('release.json is missing or does not name a valid version.');
        }

        return ['version' => Version::normalize($release['version']), 'repository' => (string) ($release['repository'] ?? ''), 'entries' => count($entries), 'bytes' => array_sum(array_column($entries, 'size'))];
    }

    /**
     * @return array<string, array{index: int, size: int}> file entries keyed by their path below the root
     */
    private function entries(ZipArchive $zip): array
    {
        if ($zip->numFiles > self::MAX_ENTRIES) {
            throw new UpdateException('The release archive contains too many entries.');
        }
        $entries = [];
        $total = 0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);
            if ($name === '' || str_contains($name, "\0") || str_contains($name, '\\') || str_starts_with($name, '/') || preg_match('#^[A-Za-z]:|[\x00-\x1f]#', $name) === 1) {
                throw new UpdateException("The release archive contains an unsafe path: {$name}");
            }
            if (! str_starts_with($name, self::ROOT)) {
                throw new UpdateException("The release archive has an entry outside perysee/: {$name}");
            }
            $relative = substr($name, strlen(self::ROOT));
            if ($relative === '') {
                continue;
            }
            foreach (explode('/', rtrim($relative, '/')) as $segment) {
                if ($segment === '' || $segment === '.' || $segment === '..') {
                    throw new UpdateException("The release archive contains an unsafe path: {$name}");
                }
            }
            $zip->getExternalAttributesIndex($index, $system, $attributes);
            $type = ($attributes >> 16) & 0170000;
            if ($system === ZipArchive::OPSYS_UNIX && $type !== 0 && $type !== 0100000 && $type !== 040000) {
                throw new UpdateException("The release archive contains a link or special file: {$name}");
            }
            if (str_ends_with($relative, '/')) {
                continue;
            }
            if (in_array($relative, self::FORBIDDEN, true) || str_starts_with($relative, 'storage/') && ! str_ends_with($relative, '.gitignore') || str_starts_with($relative, 'modules/')) {
                throw new UpdateException("The release archive ships local state that an update must not touch: {$relative}");
            }
            if (isset($entries[$relative])) {
                throw new UpdateException("The release archive lists {$relative} twice.");
            }
            $size = (int) ($zip->statIndex($index)['size'] ?? 0);
            $total += $size;
            if ($total > self::MAX_BYTES) {
                throw new UpdateException('The release archive is too large once unpacked.');
            }
            $entries[$relative] = ['index' => $index, 'size' => $size];
        }

        return $entries;
    }

    /**
     * @param  list<string>  $names
     */
    private function assertComplete(array $names): void
    {
        foreach (self::REQUIRED as $required) {
            if (! in_array($required, $names, true)) {
                throw new UpdateException("The release archive is incomplete: {$required} is missing.");
            }
        }
    }

    /**
     * @param  array<string, array{index: int, size: int}>  $entries
     */
    private function write(ZipArchive $zip, array $entries, string $destination): void
    {
        if (! is_dir($destination) && ! mkdir($destination, 0700, true) && ! is_dir($destination)) {
            throw new UpdateException('Could not create the staging folder.');
        }
        foreach ($entries as $relative => $entry) {
            $target = $destination.'/'.$relative;
            if (! is_dir(dirname($target)) && ! mkdir(dirname($target), 0755, true) && ! is_dir(dirname($target))) {
                throw new UpdateException("Could not create the folder for {$relative}.");
            }
            $source = $zip->getStream(self::ROOT.$relative);
            $output = fopen($target, 'wb');
            if ($source === false || $output === false) {
                throw new UpdateException("Could not unpack {$relative}.");
            }
            $copied = stream_copy_to_stream($source, $output, $entry['size'] + 1);
            fclose($source);
            fclose($output);
            // The size in the archive header is a claim: the bytes actually read must match it.
            if ($copied !== $entry['size']) {
                throw new UpdateException("The release archive is corrupt ({$relative}).");
            }
            chmod($target, 0644);
        }
    }
}
