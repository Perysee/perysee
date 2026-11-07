<?php

namespace App\Analytics\Ingestion;

/** Why the collector refused a request, or what an installation test proved. The values are stored. */
enum RejectionCause: string
{
    case UnknownSite = 'unknown_site';
    case DomainNotAllowed = 'domain_not_allowed';
    case ConsentMissing = 'consent_missing';
    case Quota = 'quota';
    case InvalidPayload = 'invalid_payload';
    case ModeMismatch = 'mode_mismatch';
    case PathExcluded = 'path_excluded';
    /** Not a rejection: a signed installation test reached the collector. */
    case ProbeOk = 'probe_ok';

    /** @return list<self> */
    public static function rejections(): array
    {
        return array_values(array_filter(self::cases(), fn (self $cause): bool => $cause !== self::ProbeOk));
    }
}
