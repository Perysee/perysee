<?php

namespace App\Http\Api;

use App\Analytics\Reporting\ReportSections;
use App\Models\ApiToken;
use Illuminate\Http\Request;

/**
 * What a token may ask for. A scope that opens a report also decides which filters a request can use: filtering a
 * report by country with a token that cannot read countries would still reveal a breakdown by country.
 */
class ApiScopes
{
    public static function token(Request $request): ApiToken
    {
        return $request->attributes->get('api_token');
    }

    public static function require(Request $request, string $scope): void
    {
        if (! self::token($request)->allows($scope)) {
            throw ApiError::insufficientScope($scope);
        }
    }

    /** The scope that each filter dimension, and the AI-source options, depend on. */
    public static function scopeOfFilter(string $dimension): string
    {
        return $dimension === 'provider' ? 'sources' : (ReportSections::sectionOf($dimension) ?? 'overview');
    }

    /**
     * Checks the filters and source options of an already validated period against the token.
     *
     * @param  array<string, mixed>  $period
     */
    public static function requireForPeriod(Request $request, array $period): void
    {
        foreach (array_keys($period['filters'] ?? []) as $dimension) {
            self::require($request, self::scopeOfFilter($dimension));
        }
        if (($period['source_group'] ?? 'all') !== 'all' || ! empty($period['provider'])) {
            self::require($request, 'sources');
        }
    }
}
