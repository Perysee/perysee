<?php

declare(strict_types=1);

namespace App\Modules\Events;

use App\Modules\Contracts\DomainEvent;

/**
 * A public share link was revoked. Revoking a link that was already revoked publishes nothing.
 *
 * @api
 */
final readonly class ShareLinkRevoked implements DomainEvent
{
    public const NAME = 'share.revoked';

    public const SCHEMA = 1;

    public function __construct(
        public int $siteId,
        public int $linkId,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function schema(): int
    {
        return self::SCHEMA;
    }

    public function siteId(): ?int
    {
        return $this->siteId;
    }

    public function payload(): array
    {
        return ['site_id' => $this->siteId, 'link_id' => $this->linkId];
    }
}
