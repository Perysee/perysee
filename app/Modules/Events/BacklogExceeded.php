<?php

declare(strict_types=1);

namespace App\Modules\Events;

use App\Modules\Contracts\DomainEvent;

/**
 * Collected events have been waiting to be consolidated for longer than the threshold. Published when an aggregation
 * pass stops for lack of time with the oldest waiting event past the threshold, then again only after the backlog
 * has been cleared.
 *
 * @api
 */
final readonly class BacklogExceeded implements DomainEvent
{
    public const NAME = 'ingest.backlog_exceeded';

    public const SCHEMA = 1;

    /** Same threshold as the warning of the instance health page. */
    public const THRESHOLD_MINUTES = 15;

    public function __construct(
        public int $pending,
        public int $oldestMinutes,
        public int $thresholdMinutes,
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
        return ['pending' => $this->pending, 'oldest_minutes' => $this->oldestMinutes, 'threshold_minutes' => $this->thresholdMinutes];
    }
}
