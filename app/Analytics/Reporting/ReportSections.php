<?php

namespace App\Analytics\Reporting;

/**
 * The single map from a named section of the reports to the dimension reports it allows. Public share links use
 * SECTIONS; the read API adds the two scopes that have no dimension (goals and funnels).
 */
final class ReportSections
{
    /** Sections a share link can expose, and the dimension reports each one allows. */
    public const SECTIONS = [
        'overview' => [],
        'pages' => ['path'],
        'sources' => ['source'],
        'campaigns' => ['campaign'],
        'devices' => ['device', 'device_brand', 'browser'],
        'countries' => ['country'],
        'events' => ['event'],
    ];

    /** Scopes an API token can carry: every section plus goals and funnels. */
    public const SCOPES = self::SECTIONS + ['goals' => [], 'funnels' => []];

    /** The section that serves a dimension report, or null when none does. */
    public static function sectionOf(string $dimension): ?string
    {
        foreach (self::SECTIONS as $section => $dimensions) {
            if (in_array($dimension, $dimensions, true)) {
                return $section;
            }
        }

        return null;
    }

    /**
     * Dimension reports the given sections allow.
     *
     * @param  list<string>  $sections
     * @return list<string>
     */
    public static function dimensions(array $sections): array
    {
        return array_values(array_unique(array_merge([], ...array_map(fn (string $section): array => self::SECTIONS[$section] ?? [], $sections))));
    }
}
