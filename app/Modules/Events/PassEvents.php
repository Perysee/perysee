<?php

declare(strict_types=1);

namespace App\Modules\Events;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * What one aggregation pass tells the modules, published only after the pass is over: its batches are committed, its
 * lock is released and its health markers are written. Nothing here runs inside a transaction, because a transaction
 * can be replayed and an event must not be.
 *
 * Delivery is at most once: if the process stops between the commit and the publication, the event is lost. A pass
 * that fails part-way still announces the conversions of its committed batches, and only those.
 */
final class PassEvents
{
    private const BACKLOG_STATE = 'domain_events:backlog';

    private Carbon $startedAt;

    private int $startedNs;

    /** @var array<string, array{site: int, goal: int, version: int, count: int}> */
    private array $conversions = [];

    public function __construct()
    {
        $this->startedAt = now('UTC');
        $this->startedNs = hrtime(true);
    }

    /**
     * Adds the conversions of one committed batch.
     *
     * @param  list<array{site: int, goal: int, version: int, count: int}>  $batch
     */
    public function committed(array $batch): void
    {
        foreach ($batch as $row) {
            $key = $row['site'].'|'.$row['goal'].'|'.$row['version'];
            $this->conversions[$key] ??= array_merge($row, ['count' => 0]);
            $this->conversions[$key]['count'] += $row['count'];
        }
    }

    /**
     * @param  int  $processed  Events consolidated by the pass.
     * @param  bool  $stoppedForTime  The pass ended because its time was used up, not because nothing was left.
     * @param  bool  $wholeInstance  False when the pass was limited to some sites: the backlog is then not the instance's.
     */
    public function finish(int $processed, bool $stoppedForTime, bool $wholeInstance = true): void
    {
        try {
            $events = app(DomainEvents::class);
            $to = now('UTC')->toIso8601String();
            $this->publishConversions();
            if ($processed > 0 && $events->listening(AggregationCompleted::class)) {
                $events->publish(new AggregationCompleted($processed, intdiv(hrtime(true) - $this->startedNs, 1_000_000), $to));
            }
            if ($wholeInstance && $events->listening(BacklogExceeded::class)) {
                $this->backlog($events, $stoppedForTime, $processed);
            }
        } catch (Throwable) {
            // An event must never change the outcome of the pass.
        }
    }

    /**
     * Announces the conversions of the batches committed so far, then forgets them: calling it again publishes
     * nothing. It is also what a pass that fails part-way calls, since those batches stay committed.
     */
    public function publishConversions(): void
    {
        try {
            $events = app(DomainEvents::class);
            $conversions = $this->conversions;
            $this->conversions = [];
            if ($conversions === [] || ! $events->listening(GoalConverted::class)) {
                return;
            }
            $from = $this->startedAt->toIso8601String();
            $to = now('UTC')->toIso8601String();
            foreach ($conversions as $row) {
                // A conversion moved to another day nets out at zero: only real new conversions are announced.
                if ($row['count'] > 0) {
                    $events->publish(new GoalConverted($row['site'], $row['goal'], $row['version'], $row['count'], $from, $to));
                }
            }
        } catch (Throwable) {
            // An event must never change the outcome of the pass.
        }
    }

    private function backlog(DomainEvents $events, bool $stoppedForTime, int $processed): void
    {
        $state = app(EventState::class);
        if (! $stoppedForTime) {
            // The pass drained everything it saw: the next backlog is a new incident. An idle pass (nothing processed) has
            // nothing to re-arm and reads nothing, which keeps its query budget.
            if ($processed > 0) {
                $state->release(self::BACKLOG_STATE);
            }

            return;
        }
        $waiting = DB::table('events')->whereNull('processed_at')->selectRaw('COUNT(*) AS pending, MIN(received_at) AS oldest')->first();
        if ($waiting === null || $waiting->oldest === null) {
            return;
        }
        $minutes = intdiv(max(0, now('UTC')->timestamp - Carbon::parse($waiting->oldest, 'UTC')->timestamp), 60);
        if ($minutes >= BacklogExceeded::THRESHOLD_MINUTES && $state->claim(self::BACKLOG_STATE)) {
            $events->publish(new BacklogExceeded((int) $waiting->pending, $minutes, BacklogExceeded::THRESHOLD_MINUTES));
        }
    }
}
