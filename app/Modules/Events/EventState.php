<?php

declare(strict_types=1);

namespace App\Modules\Events;

use Illuminate\Support\Facades\DB;

/**
 * Remembers that an incident was already announced, so a condition that lasts produces one event and not one per check.
 * The marker is a row of `analytics_state` (no table of its own); claiming it is a single atomic insert, so two requests
 * that notice the same incident at once cannot both announce it. Releasing it re-arms the event.
 */
final class EventState
{
    public function claim(string $name): bool
    {
        return DB::table('analytics_state')->insertOrIgnore([['name' => $name, 'value' => 'true', 'updated_at' => now('UTC')]]) === 1;
    }

    public function release(string $name): void
    {
        DB::table('analytics_state')->where('name', $name)->delete();
    }
}
