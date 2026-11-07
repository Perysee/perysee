<?php

declare(strict_types=1);

namespace App\Modules\Events;

use App\Modules\Contracts\DomainEvent;

/**
 * An aggregation pass consolidated at least one collected event. Published after the pass, never during collection.
 *
 * @api
 */
final readonly class AggregationCompleted implements DomainEvent
{
    public const NAME = 'aggregation.completed';

    public const SCHEMA = 1;

    public function __construct(
        public int $processed,
        public int $durationMs,
        public string $at,
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
        return ['processed' => $this->processed, 'duration_ms' => $this->durationMs, 'at' => $this->at];
    }
}
