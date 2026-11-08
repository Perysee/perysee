<?php

declare(strict_types=1);

namespace App\Analytics\Audit;

/**
 * The thresholds of the optional `cnil_exemption` audit profile: the conditions the French regulator (CNIL) lists for
 * audience measurement that may run without consent, as the maintainers read them.
 *
 * TO BE VALIDATED ON cnil.fr BEFORE PUBLICATION. Every figure below is a working value written from memory of those
 * conditions, not copied from a dated page of the regulator. Before the profile is mentioned in the public
 * documentation, each one must be checked against the current text on cnil.fr, and the date of the page it comes from
 * must be written next to it. Until then the interface calls the thresholds indicative, and so should anything that
 * quotes them. They describe a configuration; they are not legal advice and meeting them is not a statement of compliance.
 *
 * The conditions the profile looks at:
 *  - the cookie lasts 13 months at most and is not renewed automatically past that limit ({@see self::COOKIE_MAX_MONTHS});
 *  - the audience data is kept 25 months at most ({@see self::DATA_MAX_MONTHS});
 *  - the cookie belongs to the site alone, with no identifier shared between domains (a fact of lueur.js, see CookieScopeCheck);
 *  - the address of the visitor is truncated or not stored (a fact of the schema, see AddressStorageCheck);
 *  - consent is waived only for a measurement mode compatible with the above ({@see self::CONSENT_EXEMPT_MODES}).
 *
 * The values are injectable so that a test, or a maintainer who has read the page, can change them in one place.
 */
final class CnilExemptionThresholds
{
    /** Longest life of the measurement cookie, in months. To be validated on cnil.fr. */
    public const COOKIE_MAX_MONTHS = 13;

    /** Longest time audience data is kept, in months. To be validated on cnil.fr. */
    public const DATA_MAX_MONTHS = 25;

    /** Measurement modes that may run without asking for consent. To be validated on cnil.fr. */
    public const CONSENT_EXEMPT_MODES = ['pageviews', 'sessions'];

    /** The length of an average month, to turn months into the days and seconds the settings are written in. */
    public const MONTH_DAYS = 30.4375;

    /** @param  list<string>  $consentExemptModes */
    public function __construct(
        public readonly int $cookieMaxMonths = self::COOKIE_MAX_MONTHS,
        public readonly int $dataMaxMonths = self::DATA_MAX_MONTHS,
        public readonly array $consentExemptModes = self::CONSENT_EXEMPT_MODES,
    ) {
        if ($cookieMaxMonths < 0 || $dataMaxMonths < 0) {
            throw new \InvalidArgumentException('A threshold in months cannot be negative.');
        }
    }

    public function cookieMaxSeconds(): int
    {
        return $this->cookieMaxMonths * $this->monthSeconds();
    }

    public function dataMaxDays(): int
    {
        return (int) floor($this->dataMaxMonths * self::MONTH_DAYS);
    }

    private function monthSeconds(): int
    {
        return (int) floor(self::MONTH_DAYS * 86400);
    }
}
