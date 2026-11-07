<?php

declare(strict_types=1);

namespace App\Modules\Events;

use App\Analytics\InstanceHealth;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Publishes {@see HeartbeatMissed}. A scheduler that has stopped cannot report itself, so there are two vantage
 * points: an administrator opening a page while it is silent ({@see pageLoaded()}), and the scheduler itself when it
 * runs again after a silence ({@see beat()}). Nothing is read or written unless a module listens.
 */
final class SchedulerWatch
{
    /** Same threshold as the warning of the instance health page. */
    public const SILENT_MINUTES = 10;

    private const STATE = 'domain_events:heartbeat';

    /**
     * Records the heartbeat for the maintenance command, and announces a recovery when the previous one was too old.
     */
    public function beat(InstanceHealth $health): void
    {
        $events = app(DomainEvents::class);
        $previous = null;
        try {
            $previous = $events->listening(HeartbeatMissed::class) ? $health->get('heartbeat') : null;
        } catch (Throwable) {
        }
        $health->put('heartbeat', now('UTC')->toIso8601String());
        if (! is_string($previous)) {
            return;
        }
        try {
            $silent = intdiv(max(0, now('UTC')->timestamp - Carbon::parse($previous)->timestamp), 60);
            if ($silent >= self::SILENT_MINUTES) {
                app(EventState::class)->release(self::STATE);
                $events->publish(new HeartbeatMissed($silent, $previous, true));
            }
        } catch (Throwable) {
            // An event must never make the heartbeat fail.
        }
    }

    /**
     * Called when an administrator's page shows the instance warnings: announces a silent scheduler once per incident,
     * after the response.
     *
     * @param  list<array{code: string}>  $attention
     */
    public function pageLoaded(array $attention): void
    {
        $events = app(DomainEvents::class);
        if (! $events->listening(HeartbeatMissed::class) || ! in_array('scheduler', array_column($attention, 'code'), true)) {
            return;
        }
        try {
            $heartbeat = app(InstanceHealth::class)->get('heartbeat');
            // A scheduler that never ran has no silence to measure.
            if (! is_string($heartbeat)) {
                return;
            }
            $silent = intdiv(max(0, now('UTC')->timestamp - Carbon::parse($heartbeat)->timestamp), 60);
            if ($silent >= self::SILENT_MINUTES && app(EventState::class)->claim(self::STATE)) {
                $events->publishAfterResponse(new HeartbeatMissed($silent, $heartbeat, false));
            }
        } catch (Throwable) {
            // A page must never fail because of an event.
        }
    }
}
