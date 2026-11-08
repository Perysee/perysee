<?php

declare(strict_types=1);

namespace App\Analytics\Audit;

/**
 * The thresholds a configuration is compared to. The `generic` profile is the default and signals only the absence of
 * a reasonable limit; the other profiles are optional and chosen on the page, never stored. A profile changes what a
 * check says about a value, not what the check reads.
 */
final class AuditProfile
{
    public const GENERIC = 'generic';

    public const CNIL = 'cnil_exemption';

    /** Raw events kept longer than this are worth a look in the generic profile (the default is 90 days). */
    public const GENERIC_RAW_MAX_DAYS = 365;

    /** Daily statistics kept longer than this are worth a look in the generic profile (the default is 1095 days). */
    public const GENERIC_STATS_MAX_DAYS = 1825;

    /** Modes that set no cookie: the only ones the generic profile accepts without a consent requirement. */
    public const GENERIC_CONSENT_EXEMPT_MODES = ['pageviews'];

    /**
     * @param  list<string>  $consentExemptModes  Measurement modes a site may use without asking for a choice.
     * @param  int|null  $cookieMaxSeconds  The longest a cookie of the tracker may live, null when the profile sets no limit.
     * @param  CnilExemptionThresholds|null  $cnil  Set for the `cnil_exemption` profile only.
     */
    private function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $note,
        public readonly int $rawRetentionMaxDays,
        public readonly int $statsRetentionMaxDays,
        public readonly array $consentExemptModes,
        public readonly ?int $cookieMaxSeconds = null,
        public readonly ?CnilExemptionThresholds $cnil = null,
    ) {}

    public static function generic(): self
    {
        return new self(self::GENERIC, __('Generic'), __('Signals a missing or very generous limit. It makes no reference to a regulator.'), self::GENERIC_RAW_MAX_DAYS, self::GENERIC_STATS_MAX_DAYS, self::GENERIC_CONSENT_EXEMPT_MODES);
    }

    /**
     * Optional, never the default. Raw events and statistics share the one limit on how long audience data is kept.
     * The note says what the profile is and is not; the thresholds themselves are those of {@see CnilExemptionThresholds}.
     */
    public static function cnil(?CnilExemptionThresholds $thresholds = null): self
    {
        $thresholds ??= new CnilExemptionThresholds;

        return new self(
            self::CNIL,
            __('CNIL exemption'),
            __('Compares the settings with indicative thresholds inspired by the conditions the French regulator (CNIL) lists for audience measurement that may run without consent: a cookie of :cookie months at most, data kept :data months at most, a cookie specific to the site and no stored visitor address.', ['cookie' => $thresholds->cookieMaxMonths, 'data' => $thresholds->dataMaxMonths]),
            $thresholds->dataMaxDays(),
            $thresholds->dataMaxDays(),
            $thresholds->consentExemptModes,
            $thresholds->cookieMaxSeconds(),
            $thresholds,
        );
    }

    /** The profile asked for, the generic one when the identifier is unknown or missing. */
    public static function fromId(mixed $id, ?CnilExemptionThresholds $cnil = null): self
    {
        return $id === self::CNIL ? self::cnil($cnil) : self::generic();
    }

    /** @return list<self> The profiles the page offers, the default first. */
    public static function all(?CnilExemptionThresholds $cnil = null): array
    {
        return [self::generic(), self::cnil($cnil)];
    }
}
