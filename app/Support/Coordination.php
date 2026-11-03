<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The small, shared and short-lived state the collector and the health page depend on: a request rate limit
 * and failure counters. The default implementation uses the application cache. A module may bind another
 * implementation in the container to move this state elsewhere; it must never decide whether an event is
 * stored, which stays a database matter.
 */
interface Coordination
{
    /**
     * Counts one attempt and tells whether it is allowed. A refused attempt is not counted.
     */
    public function throttle(string $key, int $maxAttempts, int $decaySeconds): bool;

    /**
     * Records one failure of the named kind. It must never throw: a diagnostic cannot replace the original error.
     */
    public function failure(string $name): void;

    public function failures(string $name): int;
}
