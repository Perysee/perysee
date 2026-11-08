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

    /** Raw events kept longer than this are worth a look in the generic profile (the default is 90 days). */
    public const GENERIC_RAW_MAX_DAYS = 365;

    /** Daily statistics kept longer than this are worth a look in the generic profile (the default is 1095 days). */
    public const GENERIC_STATS_MAX_DAYS = 1825;

    /** Modes that set no cookie: the only ones the generic profile accepts without a consent requirement. */
    public const GENERIC_CONSENT_EXEMPT_MODES = ['pageviews'];

    /**
     * @param  list<string>  $consentExemptModes  Measurement modes a site may use without asking for a choice.
     * @param  int|null  $cookieMaxSeconds  The longest a cookie of the tracker may live, null when the profile sets no limit.
     */
    private function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $note,
        public readonly int $rawRetentionMaxDays,
        public readonly int $statsRetentionMaxDays,
        public readonly array $consentExemptModes,
        public readonly ?int $cookieMaxSeconds = null,
    ) {}

    public static function generic(): self
    {
        return new self(self::GENERIC, __('Generic'), __('Signals a missing or very generous limit. It makes no reference to a regulator.'), self::GENERIC_RAW_MAX_DAYS, self::GENERIC_STATS_MAX_DAYS, self::GENERIC_CONSENT_EXEMPT_MODES);
    }

    /** The profile asked for, the generic one when the identifier is unknown or missing. */
    public static function fromId(mixed $id): self
    {
        return self::generic();
    }

    /** @return list<self> The profiles the page offers, the default first. */
    public static function all(): array
    {
        return [self::generic()];
    }
}
