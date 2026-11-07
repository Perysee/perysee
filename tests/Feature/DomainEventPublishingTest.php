<?php

namespace Tests\Feature;

use App\Analytics\Aggregation\Aggregator;
use App\Analytics\Aggregation\Goals;
use App\Analytics\InstanceHealth;
use App\Installation\InstallationState;
use App\Models\User;
use App\Models\Website;
use App\Modules\Contracts\DomainEvent;
use App\Modules\Events\AggregationCompleted;
use App\Modules\Events\BacklogExceeded;
use App\Modules\Events\DomainEvents;
use App\Modules\Events\GoalConverted;
use App\Modules\Events\HeartbeatMissed;
use App\Modules\ModuleFailures;
use App\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

class DomainEventPublishingTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<DomainEvent> */
    private array $received = [];

    protected function setUp(): void
    {
        parent::setUp();
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
        $modules = $this->installationDirectory.'/modules';
        mkdir($modules);
        File::ensureDirectoryExists($modules.'/watcher');
        file_put_contents($modules.'/watcher/module.json', json_encode(['id' => 'watcher', 'name' => 'Watcher', 'version' => '1.0.0', 'core' => '0.1.0']));
        config(['perysee.modules_path' => $modules, 'perysee.version' => '0.4.0']);
        Cache::flush();
        app()->forgetInstance(ModuleRegistry::class);
        app()->forgetInstance(ModuleFailures::class);
        app()->forgetInstance(DomainEvents::class);
        app(ModuleRegistry::class)->enable('watcher');
    }

    private function listenTo(string ...$events): void
    {
        foreach ($events as $event) {
            app(DomainEvents::class)->listen('watcher', $event, function (DomainEvent $received): void {
                $this->received[] = $received;
            });
        }
    }

    /** @return list<array<string, mixed>> */
    private function payloads(string $name): array
    {
        return array_values(array_map(fn (DomainEvent $event): array => $event->payload(), array_filter($this->received, fn (DomainEvent $event): bool => $event->name() === $name)));
    }

    private function site(): Website
    {
        return Website::factory()->create(['tracking_mode' => 'sessions']);
    }

    private function goal(Website $site, string $value, int $threshold = 1, int $snapshot = 0): int
    {
        $id = DB::table('goals')->insertGetId(['website_id' => $site->id, 'name' => 'Goal', 'current_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('goal_versions')->insert(['website_id' => $site->id, 'goal_id' => $id, 'version' => 1, 'type' => 'event', 'value' => $value, 'threshold' => $threshold, 'snapshot_id' => $snapshot, 'created_at' => now()]);

        return $id;
    }

    private function pageview(Website $site, string $session, string $time): int
    {
        return DB::table('events')->insertGetId(['website_id' => $site->id, 'received_at' => $time, 'event_type' => 1, 'path' => '/', 'tracking_mode' => 'sessions', 'session_key' => $session, 'consent_given' => true]);
    }

    private function named(Website $site, string $session, string $time, string $name): int
    {
        return DB::table('events')->insertGetId(['website_id' => $site->id, 'received_at' => $time, 'event_type' => 2, 'event_name' => $name, 'path' => '/', 'tracking_mode' => 'sessions', 'session_key' => $session, 'consent_given' => true]);
    }

    public function test_a_pass_announces_new_conversions_per_goal_and_the_completed_aggregation(): void
    {
        $this->listenTo(GoalConverted::class, AggregationCompleted::class);
        $site = $this->site();
        $goal = $this->goal($site, 'signup');
        foreach (['s1', 's2', 's3'] as $session) {
            $this->pageview($site, $session, '2026-10-24 10:00:00');
        }
        $this->named($site, 's1', '2026-10-24 10:01:00', 'signup');
        $this->named($site, 's1', '2026-10-24 10:02:00', 'signup');
        $this->named($site, 's2', '2026-10-24 10:03:00', 'signup');

        app(Aggregator::class)->run();

        $converted = $this->payloads('goal.converted');
        $this->assertCount(1, $converted);
        $this->assertSame(['site_id' => $site->id, 'goal_id' => $goal, 'version' => 1, 'conversions' => 2], array_intersect_key($converted[0], array_flip(['site_id', 'goal_id', 'version', 'conversions'])));
        $this->assertStringNotContainsString('s1', json_encode($converted));
        $completed = $this->payloads('aggregation.completed');
        $this->assertCount(1, $completed);
        $this->assertSame(6, $completed[0]['processed']);
        $this->assertGreaterThanOrEqual(0, $completed[0]['duration_ms']);
    }

    public function test_a_threshold_goal_announces_the_conversion_once_and_not_the_hits(): void
    {
        $this->listenTo(GoalConverted::class);
        $site = $this->site();
        $this->goal($site, 'view', 2);
        $this->pageview($site, 's1', '2026-10-24 10:00:00');
        $this->named($site, 's1', '2026-10-24 10:01:00', 'view');
        app(Aggregator::class)->run();
        $this->assertSame([], $this->payloads('goal.converted'));

        $this->named($site, 's1', '2026-10-24 10:02:00', 'view');
        $this->named($site, 's1', '2026-10-24 10:03:00', 'view');
        app(Aggregator::class)->run();
        app(Aggregator::class)->run();

        $this->assertSame([1], array_column($this->payloads('goal.converted'), 'conversions'));
    }

    public function test_a_conversion_that_only_moves_to_another_day_is_not_announced_again(): void
    {
        $this->listenTo(GoalConverted::class);
        $site = $this->site();
        $this->goal($site, 'signup');
        $this->pageview($site, 's1', '2026-10-24 10:00:00');
        $this->named($site, 's1', '2026-10-25 09:00:00', 'signup');
        app(Aggregator::class)->run();
        $this->assertCount(1, $this->payloads('goal.converted'));

        $this->named($site, 's1', '2026-10-24 10:30:00', 'signup');
        app(Aggregator::class)->run();

        $this->assertCount(1, $this->payloads('goal.converted'), 'The earlier event moved the conversion; nothing new converted.');
    }

    public function test_a_backfill_or_a_rebuild_announces_nothing(): void
    {
        $this->listenTo(GoalConverted::class, AggregationCompleted::class);
        $site = $this->site();
        $this->pageview($site, 's1', '2026-10-24 10:00:00');
        $old = $this->named($site, 's1', '2026-10-24 10:01:00', 'signup');
        DB::table('events')->update(['processed_at' => now()]);
        $goal = $this->goal($site, 'signup', 1, $old);
        app(Goals::class)->queueBackfill($site->id, $goal, 1, $old);

        $rebuilt = app(Goals::class)->rebuild(500);

        $this->assertGreaterThan(0, $rebuilt);
        $this->assertSame(1, (int) DB::table('stats_goal_daily')->sum('conversions'));
        $this->assertSame([], $this->received);
    }

    public function test_an_idle_pass_and_a_failed_pass_announce_nothing(): void
    {
        $this->listenTo(GoalConverted::class, AggregationCompleted::class);
        app(Aggregator::class)->run();
        $this->assertSame([], $this->received);

        DB::table('events')->insert(['website_id' => 999999, 'received_at' => '2026-10-24 10:00:00', 'event_type' => 1, 'path' => '/', 'tracking_mode' => 'sessions', 'session_key' => 'x', 'consent_given' => true]);
        try {
            app(Aggregator::class)->run();
            $this->fail('An event without a site must fail the pass.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame([], $this->received);
    }

    public function test_events_are_delivered_after_the_data_is_committed_and_a_failing_listener_does_not_change_the_pass(): void
    {
        $site = $this->site();
        $this->goal($site, 'signup');
        $this->pageview($site, 's1', '2026-10-24 10:00:00');
        $this->named($site, 's1', '2026-10-24 10:01:00', 'signup');
        $level = DB::transactionLevel();
        $seen = [];
        app(DomainEvents::class)->listen('watcher', AggregationCompleted::class, function () use (&$seen, $level): void {
            $seen = ['level' => DB::transactionLevel(), 'expected' => $level, 'conversions' => (int) DB::table('stats_goal_daily')->sum('conversions'), 'processed' => DB::table('events')->whereNull('processed_at')->count()];
            throw new RuntimeException('listener bug');
        });

        $count = app(Aggregator::class)->run();

        $this->assertSame(2, $count);
        $this->assertSame(['level' => $level, 'expected' => $level, 'conversions' => 1, 'processed' => 0], $seen);
        $this->assertNull(app(InstanceHealth::class)->get('aggregate_error'));
        $this->assertNotNull(app(InstanceHealth::class)->get('aggregate_success'));
        $this->assertSame(1, app(ModuleFailures::class)->total('watcher'));
    }

    public function test_a_scheduler_that_runs_again_after_a_silence_announces_the_recovery_once(): void
    {
        $this->listenTo(HeartbeatMissed::class);
        app(InstanceHealth::class)->put('heartbeat', now('UTC')->subMinutes(45)->toIso8601String());
        $previous = app(InstanceHealth::class)->get('heartbeat');

        $this->artisan('perysee:maintenance')->assertSuccessful();
        $this->artisan('perysee:maintenance')->assertSuccessful();

        $payloads = $this->payloads('scheduler.heartbeat_missed');
        $this->assertCount(1, $payloads);
        $this->assertSame(['silent_minutes' => 45, 'last_heartbeat_at' => $previous, 'recovered' => true], $payloads[0]);
    }

    public function test_a_short_silence_a_first_run_and_no_listener_announce_nothing_and_cost_nothing_more(): void
    {
        $this->listenTo(HeartbeatMissed::class);
        $this->artisan('perysee:maintenance')->assertSuccessful();
        app(InstanceHealth::class)->put('heartbeat', now('UTC')->subMinutes(9)->toIso8601String());
        $this->artisan('perysee:maintenance')->assertSuccessful();
        $this->assertSame([], $this->received);

        $this->received = [];
        app()->forgetInstance(DomainEvents::class);
        app(InstanceHealth::class)->put('heartbeat', now('UTC')->subMinutes(90)->toIso8601String());
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql.' '.json_encode($query->bindings);
        });
        $this->artisan('perysee:maintenance')->assertSuccessful();

        $this->assertSame([], $this->received);
        $this->assertEmpty(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'domain_events:') || (str_starts_with($sql, 'select') && str_contains($sql, '"heartbeat"'))), 'Without a listener the heartbeat is neither read nor compared.');
    }

    public function test_an_administrator_page_announces_a_silent_scheduler_once_per_incident_after_the_response(): void
    {
        $this->listenTo(HeartbeatMissed::class);
        $admin = User::factory()->create(['is_admin' => true]);
        app(InstanceHealth::class)->put('heartbeat', now('UTC')->subMinutes(25)->toIso8601String());

        $this->actingAs($admin)->get('/dashboard')->assertOk();
        $this->actingAs($admin)->get('/dashboard')->assertOk();

        $payloads = $this->payloads('scheduler.heartbeat_missed');
        $this->assertCount(1, $payloads);
        $this->assertFalse($payloads[0]['recovered']);
        $this->assertSame(25, $payloads[0]['silent_minutes']);

        // The scheduler comes back (recovery announced, incident closed), then goes silent again: a new incident.
        $this->artisan('perysee:maintenance')->assertSuccessful();
        app(InstanceHealth::class)->put('heartbeat', now('UTC')->subMinutes(12)->toIso8601String());
        $this->actingAs($admin)->get('/dashboard')->assertOk();

        $this->assertSame([false, true, false], array_column($this->payloads('scheduler.heartbeat_missed'), 'recovered'));
    }

    public function test_a_scheduler_that_never_ran_and_a_non_administrator_announce_nothing(): void
    {
        $this->listenTo(HeartbeatMissed::class);
        $admin = User::factory()->create(['is_admin' => true]);
        $member = User::factory()->create(['is_admin' => false]);

        $this->actingAs($admin)->get('/dashboard')->assertOk();
        app(InstanceHealth::class)->put('heartbeat', now('UTC')->subMinutes(40)->toIso8601String());
        $this->actingAs($member)->get('/dashboard')->assertOk();

        $this->assertSame([], $this->received);
    }

    public function test_a_pass_that_stops_for_lack_of_time_announces_a_backlog_older_than_fifteen_minutes_once(): void
    {
        $this->listenTo(BacklogExceeded::class);
        $site = $this->site();
        foreach ([40, 39, 38] as $minutes) {
            $this->pageview($site, 's'.$minutes, now('UTC')->subMinutes($minutes)->format('Y-m-d H:i:s'));
        }

        $this->slowFirstBatch();
        app(Aggregator::class)->run(1, 1);
        $this->slowFirstBatch();
        app(Aggregator::class)->run(1, 1);

        $payloads = $this->payloads('ingest.backlog_exceeded');
        $this->assertCount(1, $payloads, 'One announcement per crossing, not one per pass.');
        $this->assertSame(1, $payloads[0]['pending'] > 0 ? 1 : 0);
        $this->assertGreaterThanOrEqual(15, $payloads[0]['oldest_minutes']);
        $this->assertSame(15, $payloads[0]['threshold_minutes']);

        // Once a pass drains everything the event is armed again.
        app(Aggregator::class)->run();
        $this->pageview($site, 'late-1', now('UTC')->subMinutes(30)->format('Y-m-d H:i:s'));
        $this->pageview($site, 'late-2', now('UTC')->subMinutes(30)->format('Y-m-d H:i:s'));
        $this->slowFirstBatch();
        app(Aggregator::class)->run(1, 1);

        $this->assertCount(2, $this->payloads('ingest.backlog_exceeded'));
    }

    public function test_a_young_backlog_is_not_announced(): void
    {
        $this->listenTo(BacklogExceeded::class);
        $site = $this->site();
        foreach ([3, 2, 1] as $minutes) {
            $this->pageview($site, 's'.$minutes, now('UTC')->subMinutes($minutes)->format('Y-m-d H:i:s'));
        }

        $this->slowFirstBatch();
        app(Aggregator::class)->run(1, 1);

        $this->assertSame([], $this->received);
    }

    /** Makes the next batch read take longer than the one-second minimum budget of a pass. */
    private function slowFirstBatch(): void
    {
        $slept = false;
        DB::listen(function ($query) use (&$slept): void {
            if (! $slept && str_contains($query->sql, 'from "events"') || ! $slept && str_contains($query->sql, 'from `events`')) {
                $slept = true;
                usleep(1_100_000);
            }
        });
    }
}
