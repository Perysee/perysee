<?php

declare(strict_types=1);

namespace App\Support;

use App\Analytics\InstanceHealth;
use Illuminate\Support\Facades\Cache;

/** Instance-wide security settings, kept with the other instance state. */
final class InstanceSecurity
{
    private const KEY = 'require_two_factor';

    private const CACHE_KEY = 'perysee.require_two_factor';

    public function __construct(private readonly InstanceHealth $health) {}

    /** Read on every signed-in request, so the answer is cached for a few minutes and dropped when it changes. */
    public function twoFactorRequired(): bool
    {
        return (bool) Cache::remember(self::CACHE_KEY, 300, fn (): bool => (bool) $this->health->get(self::KEY, false));
    }

    public function requireTwoFactor(bool $required): void
    {
        $this->health->put(self::KEY, $required);
        Cache::forget(self::CACHE_KEY);
    }
}
