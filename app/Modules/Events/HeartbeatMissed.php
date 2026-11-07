<?php

declare(strict_types=1);

namespace App\Modules\Events;

use App\Modules\Contracts\DomainEvent;

/**
 * The scheduler stayed silent for ten minutes or more. Published once per incident: when an administrator opens a page
 * while it is silent (recovered = false), and when it runs again (recovered = true). A scheduler that is stopped
 * cannot report itself, so without an administrator on the site the first event may arrive at recovery.
 *
 * @api
 */
final readonly class HeartbeatMissed implements DomainEvent
{
    public const NAME = 'scheduler.heartbeat_missed';

    public const SCHEMA = 1;

    public function __construct(
        public int $silentMinutes,
        public string $lastHeartbeatAt,
        public bool $recovered,
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
        return null;
    }

    public function payload(): array
    {
        return ['silent_minutes' => $this->silentMinutes, 'last_heartbeat_at' => $this->lastHeartbeatAt, 'recovered' => $this->recovered];
    }
}
