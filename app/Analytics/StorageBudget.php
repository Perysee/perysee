<?php

declare(strict_types=1);

namespace App\Analytics;

use App\Analytics\Continuity\ContinuityLog;
use App\Modules\Events\ContinuityBreakRecorded;
use App\Modules\Events\DomainEvents;
use App\Modules\Events\EventState;
use App\Modules\Events\StorageBudgetExceeded;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The storage budget and the adaptive retention built on it (ADR 011). The administrator sets a budget in bytes and
 * a mode: `off` (only an alert), `ask` (a tier is applied once the administrator agreed to it) or `auto`.
 *
 * Tiers ({@see StorageTiers}) are computed and reversible: one step up when the database is at or over the budget,
 * at most one step a day, all of them lifted when it is back under 80 %. They never rewrite the retention settings;
 * {@see InstanceHealth::effectiveRetention()} caps them. The state lives in `analytics_state`.
 *
 * Only `run()` (the hourly command) moves a tier. Nothing here runs on the collection path.
 */
class StorageBudget
{
    public const MODES = ['off', 'ask', 'auto'];

    /** The tiers are lifted below this share of the budget, and raised from 100 %: the gap prevents flapping. */
    public const LIFT_PERCENT = 80;

    /** Hours a tier is given to take effect before the next one is applied. */
    public const ESCALATION_HOURS = 24;

    public const BUDGET = 'storage_budget_bytes';

    public const MODE = 'storage_mode';

    public const TIER = 'storage_tier';

    private const SINCE = 'storage_tier_since';

    private const AGREED = 'storage_agreement';

    private const STATE = 'storage_budget_state';

    private const EVENT_MARKER = 'domain_events:storage_budget:';

    public function __construct(private readonly InstanceHealth $health, private readonly StorageMeter $meter) {}

    /**
     * Everything stored about the budget, in one read.
     *
     * @return array{budget_bytes: ?int, mode: string, tier: int, tier_since: ?string, agreed_tier: int, state: ?array{bytes: int, ratio_percent: int, checked_at: string}}
     */
    public function settings(): array
    {
        $stored = $this->health->many([self::BUDGET, self::MODE, self::TIER, self::SINCE, self::AGREED, self::STATE]);
        $budget = $stored[self::BUDGET] ?? null;
        $mode = $stored[self::MODE] ?? 'off';

        return [
            'budget_bytes' => is_numeric($budget) && $budget > 0 ? (int) $budget : null,
            'mode' => in_array($mode, self::MODES, true) ? $mode : 'off',
            'tier' => max(0, min(StorageTiers::MAX, (int) ($stored[self::TIER] ?? 0))),
            'tier_since' => is_string($stored[self::SINCE] ?? null) ? $stored[self::SINCE] : null,
            'agreed_tier' => max(0, min(StorageTiers::MAX, (int) ($stored[self::AGREED] ?? 0))),
            'state' => is_array($stored[self::STATE] ?? null) ? $stored[self::STATE] : null,
        ];
    }

    /**
     * The tier the policy calls for, given the size of the database. Pure: no read, no write.
     *
     * @param  array{budget_bytes: ?int, mode: string, tier: int, tier_since: ?string, agreed_tier: int}  $settings
     */
    public static function decide(array $settings, int $bytes, Carbon $now): int
    {
        $budget = $settings['budget_bytes'];
        $tier = $settings['tier'];
        if ($budget === null || $settings['mode'] === 'off' || $bytes * 100 < $budget * self::LIFT_PERCENT) {
            return 0;
        }
        $next = self::nextTier($settings, $bytes, $now);
        if ($next === null) {
            return $tier;
        }

        return $settings['mode'] === 'auto' || $settings['agreed_tier'] >= $next ? $next : $tier;
    }

    /**
     * The tier that would be applied next if allowed: the database is over the budget, there is a tier left and the
     * current one has had a day to take effect. Null otherwise.
     *
     * @param  array{budget_bytes: ?int, tier: int, tier_since: ?string}  $settings
     */
    public static function nextTier(array $settings, int $bytes, Carbon $now): ?int
    {
        $tier = $settings['tier'];
        if ($settings['budget_bytes'] === null || $bytes < $settings['budget_bytes'] || $tier >= StorageTiers::MAX) {
            return null;
        }
        $settled = $tier === 0 || $settings['tier_since'] === null || Carbon::parse($settings['tier_since'])->addHours(self::ESCALATION_HOURS)->lte($now);

        return $settled ? $tier + 1 : null;
    }

    /** The hourly pass: measure, decide, and move the tier when the decision differs from the current one. */
    public function run(): void
    {
        $settings = $this->settings();
        if ($settings['budget_bytes'] === null) {
            // Nothing to watch any more: lift what a budget had applied and forget what only it needed.
            if ($settings['tier'] > 0) {
                $this->move($settings, 0, 0);
            }
            if ($settings['state'] !== null || $settings['agreed_tier'] > 0) {
                DB::table('analytics_state')->whereIn('name', [self::STATE, self::AGREED])->delete();
            }

            return;
        }
        $bytes = $this->meter->totalBytes();
        if ($bytes === null) {
            return;
        }
        $ratio = intdiv($bytes * 100, $settings['budget_bytes']);
        $this->health->put(self::STATE, ['bytes' => $bytes, 'ratio_percent' => $ratio, 'checked_at' => now('UTC')->toIso8601String()]);
        $tier = self::decide($settings, $bytes, now('UTC'));
        if ($tier !== $settings['tier']) {
            $this->move($settings, $tier, $ratio);
        } elseif ($tier === 0 && $settings['agreed_tier'] > 0) {
            $this->health->forget(self::AGREED);
        }
    }

    /**
     * The administrator allows the next step. Refused unless the mode is `ask` and that exact step is the one
     * proposed, so that nobody agrees blindly to a deeper cut than the page showed.
     */
    public function agree(int $tier): bool
    {
        $settings = $this->settings();
        if ($settings['mode'] !== 'ask' || $this->proposal($settings) !== $tier) {
            return false;
        }
        $this->health->put(self::AGREED, $tier);

        return true;
    }

    /**
     * The tier waiting for the administrator's agreement; null when nothing is waiting.
     *
     * @param  array<string, mixed>|null  $settings
     */
    public function proposal(?array $settings = null): ?int
    {
        $settings ??= $this->settings();
        $bytes = $settings['state']['bytes'] ?? null;
        if ($settings['mode'] !== 'ask' || $bytes === null) {
            return null;
        }
        $next = self::nextTier($settings, (int) $bytes, now('UTC'));

        return $next !== null && $settings['agreed_tier'] < $next ? $next : null;
    }

    /**
     * What the Health page shows. `$bytes` is the size it measured itself; without it the last hourly measurement is used.
     *
     * @return array{budget_bytes: ?int, mode: string, tier: int, ratio_percent: ?int, checked_at: ?string, tier_since: ?string, proposal: ?array{tier: int, raw_days: ?int, hourly_days: ?int}, caps: array{raw_days: ?int, hourly_days: ?int}, effective: array{raw: int, stats: int, hourly: int}}
     */
    public function status(?int $bytes = null): array
    {
        $settings = $this->settings();
        $budget = $settings['budget_bytes'];
        $measured = $bytes ?? $settings['state']['bytes'] ?? null;
        $proposal = $this->proposal($settings);
        $effective = $this->health->effectiveRetention();
        unset($effective['tier']);

        return [
            'budget_bytes' => $budget,
            'mode' => $settings['mode'],
            'tier' => $settings['tier'],
            'ratio_percent' => $budget === null || $measured === null ? null : intdiv($measured * 100, $budget),
            'checked_at' => $bytes === null ? ($settings['state']['checked_at'] ?? null) : now('UTC')->toIso8601String(),
            'tier_since' => $settings['tier_since'],
            'proposal' => $proposal === null ? null : ['tier' => $proposal] + StorageTiers::caps($proposal),
            'caps' => StorageTiers::caps($settings['tier']),
            'effective' => $effective,
        ];
    }

    /** The Health warning, from the last hourly measurement only (cheap enough for the home page), or null. */
    public function alert(): ?array
    {
        $settings = $this->settings();
        $ratio = $settings['state']['ratio_percent'] ?? null;
        if ($settings['budget_bytes'] === null || $ratio === null || ($ratio < 100 && $settings['tier'] === 0)) {
            return null;
        }
        $proposal = $this->proposal($settings);
        $caps = StorageTiers::caps($settings['tier']);
        $message = match (true) {
            $settings['tier'] > 0 => "The database is at {$ratio}% of its storage budget. Retention is reduced (tier {$settings['tier']}): "
                .($caps['raw_days'] !== null ? "raw events are kept {$caps['raw_days']} days" : '').($caps['raw_days'] !== null && $caps['hourly_days'] !== null ? ', ' : '').($caps['hourly_days'] !== null ? "hourly aggregates {$caps['hourly_days']} days" : '').'. It returns to normal under '.self::LIFT_PERCENT.'%.',
            $proposal !== null => "The database is at {$ratio}% of its storage budget. Nothing is deleted until you agree on the Health page to reduce retention (tier {$proposal}).",
            $settings['mode'] === 'off' => "The database is at {$ratio}% of its storage budget. The mode is off: nothing is deleted automatically.",
            default => "The database is at {$ratio}% of its storage budget. The next reduction waits for the previous one to take effect.",
        };

        return ['code' => 'storage_budget', 'message' => $message];
    }

    /**
     * Applies a tier: state and continuity entry together, then the event. Nothing in a transaction can be replayed:
     * the event is published after the commit.
     *
     * @param  array{mode: string, tier: int}  $settings
     */
    private function move(array $settings, int $tier, int $ratio): void
    {
        $from = $settings['tier'];
        $break = DB::transaction(function () use ($settings, $tier, $from, $ratio): ?ContinuityBreakRecorded {
            if ($tier === 0) {
                DB::table('analytics_state')->whereIn('name', [self::TIER, self::SINCE, self::AGREED])->delete();
            } else {
                $this->health->put(self::TIER, $tier);
                $this->health->put(self::SINCE, now('UTC')->toIso8601String());
            }

            // Data goes earlier from now on when the tier rises; a lifted tier only gives the settings back.
            return app(ContinuityLog::class)->record('storage_tier', null, $tier > $from ? ContinuityLog::BREAKS : ContinuityLog::NOTE, 'all', ['from' => $from, 'to' => $tier, 'ratio_percent' => $ratio, 'mode' => $settings['mode']], now('UTC')->toDateString());
        });
        // After the commit; delivery never changes the outcome of the pass.
        if ($break) {
            app(DomainEvents::class)->publish($break);
        }
        if ($tier === 0) {
            DB::table('analytics_state')->where('name', 'like', self::EVENT_MARKER.'%')->delete();

            return;
        }
        if ($tier > $from) {
            $this->announce($tier, $ratio);
        }
    }

    /** Tells the modules, once per tier and incident. Delivery is at most once: a stopped process loses the event, never repeats it. */
    private function announce(int $tier, int $ratio): void
    {
        try {
            $events = app(DomainEvents::class);
            if (! $events->listening(StorageBudgetExceeded::class) || ! app(EventState::class)->claim(self::EVENT_MARKER.$tier)) {
                return;
            }
            $forecast = app(StorageProjection::class)->forecast();
            $events->publish(new StorageBudgetExceeded($tier, $ratio, $forecast['status'] === 'projected' || $forecast['status'] === 'reached' ? $forecast['days'] : null));
        } catch (\Throwable) {
            // An event must never change the outcome of the pass.
        }
    }
}
