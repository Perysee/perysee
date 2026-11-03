<?php

declare(strict_types=1);

namespace App\Updates;

/**
 * Release versions. The configuration holds "0.2.0" and Git tags hold "v0.2.0": both are the same version.
 */
final class Version
{
    private const PATTERN = '/^v?(\d+\.\d+\.\d+)(-[0-9A-Za-z.-]+)?$/';

    public static function isValid(string $version): bool
    {
        return preg_match(self::PATTERN, $version) === 1;
    }

    public static function normalize(string $version): string
    {
        if (! self::isValid($version)) {
            throw new UpdateException("\"{$version}\" is not a version number (expected X.Y.Z).");
        }

        return ltrim($version, 'v');
    }

    public static function tag(string $version): string
    {
        return 'v'.self::normalize($version);
    }

    public static function isNewer(string $candidate, string $current): bool
    {
        return version_compare(self::normalize($candidate), self::normalize($current), '>');
    }

    public static function isPrerelease(string $version): bool
    {
        return str_contains(self::normalize($version), '-');
    }
}
