<?php

declare(strict_types=1);

namespace App\Analytics;

/**
 * The tiers of the adaptive retention (ADR 011): what a storage budget under pressure takes away, step by step.
 * A tier is a computed state, never a setting: it only ever caps the retention the administrator chose, so the
 * effective value is the lower of the two and a tier can never keep data longer than the administrator asked.
 *
 * 0 normal. 1 raw events 30 days. 2 raw events 7 days. 3 hourly aggregates 90 days. 4 hourly aggregates 30 days.
 * The daily aggregates are never touched.
 */
final class StorageTiers
{
    public const MAX = 4;

    /** Days that raw events are kept at most, by tier. */
    private const RAW = [1 => 30, 2 => 7];

    /** Days that the hourly aggregates are kept at most, by tier. */
    private const HOURLY = [3 => 90, 4 => 30];

    /** Raw events retention under a tier: the administrator's setting, or less. */
    public static function rawDays(int $setting, int $tier): int
    {
        return self::capped($setting, self::RAW, $tier);
    }

    /** Hourly aggregates retention under a tier: the statistics retention, or less. */
    public static function hourlyDays(int $statsSetting, int $tier): int
    {
        return self::capped($statsSetting, self::HOURLY, $tier);
    }

    /**
     * What a tier changes, for the page and the log: the caps it adds on top of the lower tiers.
     *
     * @return array{raw_days: ?int, hourly_days: ?int}
     */
    public static function caps(int $tier): array
    {
        return ['raw_days' => self::cap(self::RAW, $tier), 'hourly_days' => self::cap(self::HOURLY, $tier)];
    }

    /** @param  array<int, int>  $caps */
    private static function cap(array $caps, int $tier): ?int
    {
        $applicable = array_filter($caps, fn (int $from): bool => $from <= $tier, ARRAY_FILTER_USE_KEY);

        return $applicable === [] ? null : min($applicable);
    }

    /** @param  array<int, int>  $caps */
    private static function capped(int $setting, array $caps, int $tier): int
    {
        $cap = self::cap($caps, $tier);

        return $cap === null ? $setting : min($setting, $cap);
    }
}
