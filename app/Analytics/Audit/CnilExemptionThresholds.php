<?php

declare(strict_types=1);

namespace App\Analytics\Audit;

/**
 * The thresholds of the optional `cnil_exemption` audit profile: the conditions the French regulator (CNIL) lists for
 * audience measurement that may run without consent, as the maintainers read them.
 *
 * Checked on 8 November 2026 against the CNIL page "Cookies : solutions pour les outils de mesure d'audience"
 * (published 4 July 2025, https://www.cnil.fr/fr/cookies-solutions-pour-les-outils-de-mesure-daudience). It gives 13 months
 * as the example of a tracker life that allows audiences to be compared over time, not extended automatically at new
 * visits; a collection of at most 25 months; a periodic review of both durations; a purpose limited to audience
 * measurement for the publisher alone, anonymous statistical data, no cross-referencing and no global tracking.
 * Not on that page: the truncation of the last byte of the address and the right to object, which are in the CNIL sheet
 * n°16 of 11 June 2020. The CNIL self-assessment tool of July 2025 (PDF) was not read in full: its number of objectives
 * and criteria is not confirmed, so nothing here cites it. The interface still calls the thresholds indicative, and so
 * should anything that quotes them: they describe a configuration, they are not legal advice and meeting them is not a
 * statement of compliance.
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
    /** Longest life of the measurement cookie, in months: the CNIL's own example of a life that allows comparison. */
    public const COOKIE_MAX_MONTHS = 13;

    /** Longest time audience data is kept, in months: the maximum the CNIL page states. */
    public const DATA_MAX_MONTHS = 25;

    /** Measurement modes the profile treats as able to run without asking for consent: Perysee's two modes. */
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
