<?php

declare(strict_types=1);

namespace App\Modules\Events;

use App\Modules\Contracts\DomainEvent;

/**
 * The database went over the storage budget the administrator set and the adaptive retention moved up one tier
 * (ADR 011). Published once per upward transition by the hourly budget command, never by the collector, and again
 * for a tier only after the budget has been back under its lift threshold.
 *
 * Available from Perysee 0.5.0: a module that listens to it states that release in the "core" field of `module.json`.
 *
 * @api
 */
final readonly class StorageBudgetExceeded implements DomainEvent
{
    public const NAME = 'ingest.storage_budget_exceeded';

    public const SCHEMA = 1;

    /** The first Perysee release with this event; ModuleProvider::listen() refuses a module that does not require it. */
    public const SINCE = '0.5.0';

    public function __construct(
        public int $tier,
        public int $ratioPercent,
        public ?int $daysLeft,
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
        return ['tier' => $this->tier, 'ratio_percent' => $this->ratioPercent, 'days_left' => $this->daysLeft];
    }
}
