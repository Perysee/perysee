<?php

declare(strict_types=1);

namespace App\Installation;

/**
 * The MariaDB series this release runs on: the long-term-support lines that CI exercises with the full suite.
 * 10.11 is the floor because Laravel's native uuid column needs MariaDB 10.7 or later. The rolling releases between
 * two LTS lines are not listed, and MySQL is not supported: advisory locks and upserts assume the MariaDB driver.
 */
final class DatabaseVersion
{
    public const SERIES = ['10.11', '11.4', '11.8'];

    /** @param string $version the result of SELECT VERSION(), for example 11.4.8-MariaDB-ubu2404 */
    public static function supports(string $version): bool
    {
        $version = preg_replace('/^5\.5\.5-/', '', $version) ?? $version;
        if (preg_match('/^(\d+\.\d+)\.\d+.*MariaDB/i', $version, $match) !== 1) {
            return false;
        }

        return in_array($match[1], self::SERIES, true);
    }

    public static function requirement(): string
    {
        $series = self::SERIES;
        $last = array_pop($series);

        return 'This release supports MariaDB '.implode(', ', $series).' and '.$last.'.';
    }
}
