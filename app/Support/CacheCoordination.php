<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

final class CacheCoordination implements Coordination
{
    public function throttle(string $key, int $maxAttempts, int $decaySeconds): bool
    {
        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            return false;
        }
        RateLimiter::hit($key, $decaySeconds);

        return true;
    }

    public function failure(string $name): void
    {
        try {
            Cache::add('analytics:'.$name, 0, now()->addDays(7));
            Cache::increment('analytics:'.$name);
        } catch (Throwable) {
            // A diagnostic must never replace the original collection failure.
        }
    }

    public function failures(string $name): int
    {
        return (int) Cache::get('analytics:'.$name, 0);
    }
}
