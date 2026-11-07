<?php

namespace App\Analytics\Ingestion;

use App\Models\Website;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A refused collection request. It renders exactly like the plain abort() it replaces (same status, empty
 * message); the cause and what is known about the site only feed the diagnostics.
 */
class CollectionRejected extends HttpException
{
    public function __construct(
        public readonly RejectionCause $cause,
        int $status,
        public readonly ?int $websiteId = null,
        public readonly ?string $siteKey = null,
        public readonly ?string $origin = null,
    ) {
        parent::__construct($status);
    }

    public static function unless(mixed $condition, RejectionCause $cause, int $status, ?Website $site = null, ?string $siteKey = null, ?string $origin = null): void
    {
        if (! $condition) {
            throw new self($cause, $status, $site?->id, $siteKey, $origin);
        }
    }

    public static function if(mixed $condition, RejectionCause $cause, int $status, ?Website $site = null, ?string $siteKey = null, ?string $origin = null): void
    {
        self::unless(! $condition, $cause, $status, $site, $siteKey, $origin);
    }
}
