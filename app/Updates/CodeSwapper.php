<?php

declare(strict_types=1);

namespace App\Updates;

/**
 * Replaces the files of the application with those of a staged release, and puts the old ones back on demand.
 *
 * Each "unit" is a top-level file or folder of the release (a folder inside public/, bootstrap/ and database/,
 * which also hold local files). A unit is moved aside and the staged one moved in, so a unit is never half
 * written; the old units wait in a "previous" folder next to the backup. The environment file, storage/,
 * modules/ and bootstrap/cache/ are never units: an update does not touch them.
 *
 * This class runs while the code it replaces is being replaced, so it uses nothing but PHP functions.
 */
final class CodeSwapper
{
    private const PROTECTED = ['.env', 'storage', 'modules', 'node_modules', 'bootstrap/cache', 'database/seeders', 'database/factories'];

    private const SPLIT = ['public', 'bootstrap', 'database'];

    public const MANIFEST = 'swap.json';

    /**
     * @return list<string> relative paths of the units of a staged release
     */
    public function units(string $staged): array
    {
        $units = [];
        foreach (scandir($staged) ?: [] as $name) {
            if ($name === '.' || $name === '..' || in_array($name, self::PROTECTED, true)) {
                continue;
            }
            if (in_array($name, self::SPLIT, true) && is_dir($staged.'/'.$name)) {
                foreach (scandir($staged.'/'.$name) ?: [] as $child) {
                    if ($child !== '.' && $child !== '..' && ! in_array($name.'/'.$child, self::PROTECTED, true)) {
                        $units[] = $name.'/'.$child;
                    }
                }

                continue;
            }
            $units[] = $name;
        }
        sort($units);

        return $units;
    }

    /**
     * Whether moving the units can work: everything sits on one filesystem and the live paths can be renamed.
     *
     * @return list<string> problems found, empty when the swap can start
     */
    public function problems(string $staged, string $live, string $previous): array
    {
        $problems = [];
        if (! is_dir($previous) && ! @mkdir($previous, 0700, true) && ! is_dir($previous)) {
            return ['The folder for the previous version cannot be created.'];
        }
        foreach ($this->units($staged) as $unit) {
            // The folder is created when missing, so the nearest existing ancestor is the one that must accept it.
            $parent = dirname($live.'/'.$unit);
            while (! is_dir($parent) && $parent !== $live && strlen($parent) > strlen($live)) {
                $parent = dirname($parent);
            }
            if (! is_dir($parent) || ! is_writable($parent)) {
                $problems[] = 'The application folder is not writable: '.($parent === $live ? '.' : substr($parent, strlen($live) + 1));
            }
            if (file_exists($live.'/'.$unit) && ! is_writable($live.'/'.$unit) && is_dir($live.'/'.$unit)) {
                $problems[] = "{$unit} is not writable.";
            }
        }
        $probe = $live.'/.perysee-swap-probe-'.bin2hex(random_bytes(4));
        if (@file_put_contents($probe, '') === false) {
            $problems[] = 'The application folder is not writable.';
        } elseif (! @rename($probe, $previous.'/.probe')) {
            $problems[] = 'The staging, backup and application folders must be on the same filesystem.';
        } else {
            @unlink($previous.'/.probe');
        }
        if (is_file($probe)) {
            @unlink($probe);
        }

        return array_values(array_unique($problems));
    }

    /**
     * Moves the units into place. On any failure, every unit already moved is put back before the exception leaves.
     */
    public function swap(string $staged, string $live, string $previous): void
    {
        $moved = [];
        $this->writeManifest($previous, $moved);
        try {
            foreach ($this->units($staged) as $unit) {
                $hadPrevious = file_exists($live.'/'.$unit) || is_link($live.'/'.$unit);
                if ($hadPrevious) {
                    $this->ensureParent($previous.'/'.$unit);
                    $this->move($live.'/'.$unit, $previous.'/'.$unit);
                }
                $moved[] = ['unit' => $unit, 'had_previous' => $hadPrevious, 'placed' => false];
                $this->writeManifest($previous, $moved);
                $this->ensureParent($live.'/'.$unit);
                $this->move($staged.'/'.$unit, $live.'/'.$unit);
                $moved[array_key_last($moved)]['placed'] = true;
                $this->writeManifest($previous, $moved);
            }
        } catch (\Throwable $failure) {
            $this->rollback($live, $previous);

            throw new UpdateException('The files could not be replaced ('.$failure->getMessage().'). The previous version was restored.', 0, $failure);
        }
    }

    /**
     * Puts the previous units back, newest first, and removes the ones the release added.
     */
    public function rollback(string $live, string $previous): void
    {
        $manifest = json_decode((string) @file_get_contents($previous.'/'.self::MANIFEST), true);
        if (! is_array($manifest)) {
            throw new UpdateException('There is no record of a previous version to restore.');
        }
        foreach (array_reverse($manifest) as $item) {
            $unit = (string) $item['unit'];
            if ($item['placed'] ?? false) {
                $this->remove($live.'/'.$unit);
            }
            if ($item['had_previous']) {
                $this->ensureParent($live.'/'.$unit);
                $this->move($previous.'/'.$unit, $live.'/'.$unit);
            }
        }
        @unlink($previous.'/'.self::MANIFEST);
    }

    public function hasPrevious(string $previous): bool
    {
        return is_file($previous.'/'.self::MANIFEST);
    }

    /**
     * @param  list<array{unit: string, had_previous: bool, placed: bool}>  $moved
     */
    private function writeManifest(string $previous, array $moved): void
    {
        if (! is_dir($previous) && ! mkdir($previous, 0700, true) && ! is_dir($previous)) {
            throw new UpdateException('The folder for the previous version cannot be created.');
        }
        if (file_put_contents($previous.'/'.self::MANIFEST, json_encode($moved, JSON_THROW_ON_ERROR), LOCK_EX) === false) {
            throw new UpdateException('The swap record cannot be written.');
        }
    }

    private function ensureParent(string $path): void
    {
        $parent = dirname($path);
        if (! is_dir($parent) && ! mkdir($parent, 0755, true) && ! is_dir($parent)) {
            throw new UpdateException("Cannot create {$parent}.");
        }
    }

    private function move(string $from, string $to): void
    {
        if (! rename($from, $to)) {
            throw new UpdateException("Cannot move {$from}.");
        }
    }

    public function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (! is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                $this->remove($path.'/'.$name);
            }
        }
        @rmdir($path);
    }
}
