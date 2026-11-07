<?php

declare(strict_types=1);

namespace App\Modules\Events;

use App\Modules\Contracts\DomainEvent;

/**
 * Someone accepted an invitation to a site. Published after the response, with the internal user id only: no name,
 * no address.
 *
 * @api
 */
final readonly class InvitationAccepted implements DomainEvent
{
    public const NAME = 'team.invitation_accepted';

    public const SCHEMA = 1;

    public function __construct(
        public int $siteId,
        public string $role,
        public bool $newUser,
        public int $memberId,
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
        return ['site_id' => $this->siteId, 'role' => $this->role, 'new_user' => $this->newUser, 'member_id' => $this->memberId];
    }
}
