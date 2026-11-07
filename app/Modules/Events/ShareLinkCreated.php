<?php

declare(strict_types=1);

namespace App\Modules\Events;

use App\Modules\Contracts\DomainEvent;

/**
 * A public share link was created. The token, its hint and its label are never part of the event.
 *
 * @api
 */
final readonly class ShareLinkCreated implements DomainEvent
{
    public const NAME = 'share.created';

    public const SCHEMA = 1;

    /**
     * @param  list<string>  $sections
     */
    public function __construct(
        public int $siteId,
        public int $linkId,
        public array $sections,
        public bool $protected,
        public ?string $expiresAt,
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
        return ['site_id' => $this->siteId, 'link_id' => $this->linkId, 'sections' => array_values($this->sections), 'protected' => $this->protected, 'expires_at' => $this->expiresAt];
    }
}
