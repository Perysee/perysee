<?php

declare(strict_types=1);

namespace App\Modules\Events;

use App\Modules\Contracts\DomainEvent;
use App\Modules\ModuleFailures;
use App\Modules\ModuleProvider;
use App\Modules\ModuleRegistry;
use App\Modules\ModuleScope;
use Closure;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * The bus between the Core and the modules. Listeners live in memory for one request: a module registers them when
 * it boots, so there is nothing to store and nothing to read while nobody listens.
 *
 * Delivery is synchronous and best effort (at most once, no ordering between modules, no replay). Each listener runs
 * in its own try/catch: a failure is counted against the module that registered it ({@see ModuleFailures}) and never
 * reaches the publisher or the other modules. A module disabled or put in quarantine while an event is being
 * delivered receives nothing more, and an event about a site reaches only the modules active on that site.
 *
 * The collector never publishes: only the scheduler, the maintenance and the administration publish events.
 *
 * @internal Modules subscribe with {@see ModuleProvider::listen()}.
 */
final class DomainEvents
{
    /** Every event of the catalog, for tests and tooling. */
    public const CATALOG = [
        GoalConverted::class,
        AggregationCompleted::class,
        HeartbeatMissed::class,
        BacklogExceeded::class,
        InvitationAccepted::class,
        ShareLinkCreated::class,
        ShareLinkRevoked::class,
        ModuleQuarantined::class,
        StorageBudgetExceeded::class,
    ];

    /** Events published while another one is being delivered wait for it; this caps how many follow one publication. */
    private const QUEUE_LIMIT = 50;

    /** @var array<class-string<DomainEvent>, list<array{module: string, listener: Closure|string}>> */
    private array $listeners = [];

    private bool $delivering = false;

    /** @var list<DomainEvent> */
    private array $queue = [];

    /**
     * @param  class-string<DomainEvent>  $event
     * @param  Closure(DomainEvent): mixed|class-string  $listener  A closure, or the name of a class with an __invoke method, built when the event arrives.
     */
    public function listen(string $module, string $event, Closure|string $listener): void
    {
        if (! is_subclass_of($event, DomainEvent::class)) {
            throw new InvalidArgumentException("\"{$event}\" is not a Perysee domain event.");
        }
        if (is_string($listener) && ! (class_exists($listener) && method_exists($listener, '__invoke'))) {
            throw new InvalidArgumentException("Listener \"{$listener}\" must be a closure or the name of a class with an __invoke method.");
        }
        $this->listeners[$event][] = ['module' => $module, 'listener' => $listener];
    }

    /**
     * Whether any module subscribed to this event. Publishers ask first, so that nothing is computed or read for an
     * event nobody wants.
     *
     * @param  class-string<DomainEvent>  $event
     */
    public function listening(string $event): bool
    {
        return ($this->listeners[$event] ?? []) !== [];
    }

    /**
     * Delivers now. Use it from the scheduler and from commands, after the data is committed.
     */
    public function publish(DomainEvent $event): void
    {
        if (! $this->listening($event::class)) {
            return;
        }
        if ($this->delivering) {
            // A listener failed badly enough to publish something (a quarantine): it is delivered right after, once.
            if (count($this->queue) < self::QUEUE_LIMIT) {
                $this->queue[] = $event;
            }

            return;
        }
        $this->delivering = true;
        try {
            $this->deliver($event);
            // The chain is bounded in length, not only in width: a listener that republishes its own event ends here.
            for ($chained = 0; $chained < self::QUEUE_LIMIT && ($next = array_shift($this->queue)) !== null; $chained++) {
                $this->deliver($next);
            }
        } catch (Throwable $exception) {
            Log::warning('A domain event could not be delivered.', ['exception' => $exception]);
        } finally {
            $this->delivering = false;
            $this->queue = [];
        }
    }

    /**
     * Delivers once the response has been sent. Use it from administrators' requests.
     */
    public function publishAfterResponse(DomainEvent $event): void
    {
        if ($this->listening($event::class)) {
            // The callback stays registered on a long-lived application (tests, Octane): it must fire once.
            $sent = false;
            app()->terminating(function () use ($event, &$sent): void {
                if (! $sent) {
                    $sent = true;
                    $this->publish($event);
                }
            });
        }
    }

    private function deliver(DomainEvent $event): void
    {
        $registry = app(ModuleRegistry::class);
        $budget = max(1, (int) config('perysee.module_events.budget_ms', 2000)) * 1_000_000;
        $started = hrtime(true);
        $listeners = $this->listeners[$event::class] ?? [];
        foreach ($listeners as $index => ['module' => $module, 'listener' => $listener]) {
            if (hrtime(true) - $started >= $budget) {
                Log::warning("Domain event {$event->name()} was not delivered to ".(count($listeners) - $index).' listener(s): the delivery time budget was used up.');

                return;
            }
            // Re-read every time: an earlier listener may have put this module in quarantine.
            if (! ($registry->all()[$module]->enabled ?? false)) {
                continue;
            }
            $site = $event->siteId();
            if ($site !== null && ! app(ModuleScope::class)->isActiveForSiteId($module, $site)) {
                continue;
            }
            try {
                ($listener instanceof Closure ? $listener : app($listener))($event);
            } catch (Throwable $exception) {
                app(ModuleFailures::class)->report($module, $exception, 'event:'.$event->name());

                continue;
            }
            if (hrtime(true) - $started >= $budget) {
                // PHP cannot interrupt a listener; the one that used up the budget is counted as failing, the rest wait for the next event.
                app(ModuleFailures::class)->report($module, new RuntimeException('The listener used up the delivery time budget.'), 'event:'.$event->name().' too slow');
            }
        }
    }
}
