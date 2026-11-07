<?php

declare(strict_types=1);

namespace App\Modules\Events;

use App\Modules\Contracts\DomainEvent;

/**
 * New conversions of a goal version counted by one aggregation pass (the live path only: a backfill or a rebuild
 * publishes nothing). One event per goal version and pass, never negative, never a session.
 *
 * @api
 */
final readonly class GoalConverted implements DomainEvent
{
    public const NAME = 'goal.converted';

    public const SCHEMA = 1;

    public function __construct(
        public int $siteId,
        public int $goalId,
        public int $version,
        public int $conversions,
        public string $from,
        public string $to,
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
        return ['site_id' => $this->siteId, 'goal_id' => $this->goalId, 'version' => $this->version, 'conversions' => $this->conversions, 'from' => $this->from, 'to' => $this->to];
    }
}
