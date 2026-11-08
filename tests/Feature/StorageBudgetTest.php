<?php

namespace Tests\Feature;

use App\Analytics\InstanceHealth;
use App\Analytics\StorageBudget;
use App\Analytics\StorageMeter;
use App\Models\Website;
use App\Modules\Events\ContinuityBreakRecorded;
use App\Modules\Events\DomainEvents;
use App\Modules\Events\StorageBudgetExceeded;
use App\Modules\ModuleRegistry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StorageBudgetTest extends TestCase
{
    use RefreshDatabase;

    private const BUDGET = 1000000;

    /** The size the engine reports, set by each test. */
    private int $bytes = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $test = $this;
        $this->app->instance(StorageMeter::class, new class($test) extends StorageMeter
        {
            public function __construct(private readonly StorageBudgetTest $test) {}

            public function totalBytes(): ?int
            {
                return $this->test->currentBytes();
            }
        });
    }

    public function currentBytes(): int
    {
        return $this->bytes;
    }

    private function health(): InstanceHealth
    {
        return app(InstanceHealth::class);
    }

    private function configure(string $mode, ?int $budget = self::BUDGET): void
    {
        $budget === null ? $this->health()->forget('storage_budget_bytes') : $this->health()->put('storage_budget_bytes', $budget);
        $this->health()->put('storage_mode', $mode);
    }

    private function pass(int $bytes): void
    {
        $this->bytes = $bytes;
        $this->artisan('perysee:storage-budget')->assertSuccessful();
    }

    private function tier(): int
    {
        return app(StorageBudget::class)->settings()['tier'];
    }

    private function event(int $daysAgo): void
    {
        DB::table('events')->insert(['website_id' => Website::factory()->create()->id, 'received_at' => now('UTC')->subDays($daysAgo), 'event_type' => 1, 'path' => '/', 'processed_at' => now(), 'consent_given' => true]);
    }

    public function test_without_a_budget_a_pass_costs_one_read_and_writes_nothing(): void
    {
        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $this->pass(5 * self::BUDGET);

        $this->assertCount(1, $statements, implode(' | ', $statements));
        $this->assertStringStartsWith('select', $statements[0]);
    }

    public function test_in_mode_off_nothing_is_purged_and_the_health_alert_says_so(): void
    {
        $this->configure('off');
        $this->health()->put('heartbeat', now('UTC')->toIso8601String());
        $this->event(40);

        $this->pass((int) (1.3 * self::BUDGET));
        $this->artisan('perysee:prune-tracking')->assertSuccessful();

        $this->assertSame(0, $this->tier());
        $this->assertSame(1, DB::table('events')->count());
        $this->assertSame(0, DB::table('continuity_log')->count());
        $alert = collect($this->health()->attention())->firstWhere('code', 'storage_budget');
        $this->assertStringContainsString('130%', $alert['message']);
        $this->assertStringContainsString('mode is off', $alert['message']);
    }

    public function test_in_mode_ask_nothing_changes_without_the_agreement(): void
    {
        $this->configure('ask');
        $this->health()->put('heartbeat', now('UTC')->toIso8601String());
        $this->event(40);

        $this->pass((int) (1.2 * self::BUDGET));
        $this->pass((int) (1.2 * self::BUDGET));
        $this->artisan('perysee:prune-tracking')->assertSuccessful();

        $this->assertSame(0, $this->tier());
        $this->assertSame(1, DB::table('events')->count());
        $this->assertSame(1, app(StorageBudget::class)->proposal());
        $alert = collect($this->health()->attention())->firstWhere('code', 'storage_budget');
        $this->assertStringContainsString('agree', $alert['message']);
    }

    public function test_in_mode_ask_the_agreement_applies_one_tier_and_the_next_needs_its_own(): void
    {
        $this->configure('ask');
        $this->event(40);
        $this->pass((int) (1.2 * self::BUDGET));

        $this->assertFalse(app(StorageBudget::class)->agree(2), 'Only the proposed tier can be agreed.');
        $this->assertTrue(app(StorageBudget::class)->agree(1));
        $this->pass((int) (1.2 * self::BUDGET));
        $this->artisan('perysee:prune-tracking')->assertSuccessful();

        $this->assertSame(1, $this->tier());
        $this->assertSame(0, DB::table('events')->count());
        $this->assertNull(app(StorageBudget::class)->proposal(), 'The tier is given a day to take effect.');

        $this->travel(25)->hours();
        $this->pass((int) (1.2 * self::BUDGET));
        $this->assertSame(1, $this->tier(), 'Tier 2 was not agreed.');
        $this->assertSame(2, app(StorageBudget::class)->proposal());
        $this->assertTrue(app(StorageBudget::class)->agree(2));
        $this->pass((int) (1.2 * self::BUDGET));
        $this->assertSame(2, $this->tier());
    }

    public function test_the_agreement_is_refused_outside_ask_mode_or_when_nothing_is_proposed(): void
    {
        $this->configure('auto');
        $this->pass((int) (1.2 * self::BUDGET));
        $this->assertFalse(app(StorageBudget::class)->agree(1));

        $this->configure('ask');
        $this->pass((int) (0.9 * self::BUDGET));
        $this->assertFalse(app(StorageBudget::class)->agree(1), 'Under the budget there is nothing to agree to.');
    }

    public function test_in_mode_auto_the_tier_rises_and_is_lifted_under_eighty_percent(): void
    {
        $this->configure('auto');
        $this->event(40);

        $this->pass((int) (1.05 * self::BUDGET));
        $this->assertSame(1, $this->tier());
        $this->artisan('perysee:prune-tracking')->assertSuccessful();
        $this->assertSame(0, DB::table('events')->count());
        $this->assertSame(['raw' => 30, 'stats' => 1095, 'hourly' => 1095, 'tier' => 1], $this->health()->effectiveRetention());
        $this->assertSame(90, $this->health()->retentionDays('raw_retention_days'), 'The setting of the administrator is untouched.');

        $this->pass((int) (0.9 * self::BUDGET));
        $this->assertSame(1, $this->tier(), 'Between 80% and 100% the tier holds.');

        $this->pass((int) (0.79 * self::BUDGET));
        $this->assertSame(0, $this->tier());
        $this->assertSame(90, $this->health()->effectiveRetention()['raw']);
        $this->assertSame(0, DB::table('analytics_state')->whereIn('name', ['storage_tier', 'storage_tier_since', 'storage_agreement'])->count());
    }

    public function test_each_tier_change_is_written_to_the_continuity_log(): void
    {
        $this->configure('auto');

        $this->pass((int) (1.05 * self::BUDGET));
        $this->pass((int) (0.5 * self::BUDGET));

        $entries = DB::table('continuity_log')->where('kind', 'storage_tier')->orderBy('id')->get();
        $this->assertCount(2, $entries);
        $this->assertSame(['breaks', 'note'], $entries->pluck('severity')->all());
        $this->assertNull($entries[0]->website_id);
        $this->assertSame(now('UTC')->toDateString(), substr((string) $entries[0]->data_from, 0, 10));
        $this->assertSame(['from' => 0, 'to' => 1, 'ratio_percent' => 105, 'mode' => 'auto'], json_decode($entries[0]->payload, true));
        $this->assertSame(['from' => 1, 'to' => 0, 'ratio_percent' => 50, 'mode' => 'auto'], json_decode($entries[1]->payload, true));
    }

    public function test_a_pass_that_changes_nothing_writes_no_continuity_entry(): void
    {
        $this->configure('auto');
        $this->pass((int) (1.05 * self::BUDGET));
        $this->pass((int) (1.05 * self::BUDGET));
        $this->pass((int) (1.05 * self::BUDGET));

        $this->assertSame(1, DB::table('continuity_log')->count());
    }

    public function test_the_tiers_climb_a_day_apart_up_to_the_last_one(): void
    {
        $this->configure('auto');

        foreach ([1, 2, 3, 4, 4] as $expected) {
            $this->pass((int) (1.5 * self::BUDGET));
            $this->assertSame($expected, $this->tier());
            $this->pass((int) (1.5 * self::BUDGET));
            $this->assertSame($expected, $this->tier(), 'No second step within a day.');
            $this->travel(25)->hours();
        }
        $this->assertSame(['raw' => 7, 'stats' => 1095, 'hourly' => 30, 'tier' => 4], $this->health()->effectiveRetention());
    }

    public function test_a_tier_never_raises_a_retention_the_administrator_chose(): void
    {
        $this->configure('auto');
        $this->health()->put('raw_retention_days', 5);
        $this->health()->put('stats_retention_days', 20);

        foreach (range(1, 4) as $unused) {
            $this->pass(2 * self::BUDGET);
            $this->travel(25)->hours();
            $effective = $this->health()->effectiveRetention();
            $this->assertLessThanOrEqual(5, $effective['raw']);
            $this->assertLessThanOrEqual(20, $effective['hourly']);
            $this->assertSame(20, $effective['stats']);
        }
    }

    public function test_switching_to_off_or_removing_the_budget_lifts_the_tier(): void
    {
        $this->configure('auto');
        $this->pass(2 * self::BUDGET);
        $this->assertSame(1, $this->tier());

        $this->configure('off');
        $this->pass(2 * self::BUDGET);
        $this->assertSame(0, $this->tier());

        $this->configure('auto');
        $this->pass(2 * self::BUDGET);
        $this->assertSame(1, $this->tier());
        $this->configure('auto', null);
        $this->pass(2 * self::BUDGET);
        $this->assertSame(0, $this->tier());
        $this->assertSame(0, DB::table('analytics_state')->where('name', 'storage_budget_state')->count());
    }

    public function test_an_unavailable_measure_changes_nothing(): void
    {
        $this->configure('auto');
        $this->pass(2 * self::BUDGET);
        $this->app->instance(StorageMeter::class, new class extends StorageMeter
        {
            public function totalBytes(): ?int
            {
                return null;
            }
        });

        $this->artisan('perysee:storage-budget')->assertSuccessful();

        $this->assertSame(1, $this->tier());
    }

    public function test_the_event_is_published_once_per_transition_and_rearmed_after_the_incident(): void
    {
        $this->configure('auto');
        $received = [];
        app(DomainEvents::class)->listen('watcher', StorageBudgetExceeded::class, function (StorageBudgetExceeded $event) use (&$received): void {
            $received[] = $event->payload();
        });
        $this->withModuleEnabled();

        $this->pass((int) (1.1 * self::BUDGET));
        $this->pass((int) (1.1 * self::BUDGET));
        $this->assertSame([['tier' => 1, 'ratio_percent' => 110, 'days_left' => null]], $received);

        $this->travel(25)->hours();
        $this->pass((int) (1.2 * self::BUDGET));
        $this->assertSame([1, 2], array_column($received, 'tier'));

        $this->pass((int) (0.5 * self::BUDGET));
        $this->assertSame([1, 2], array_column($received, 'tier'), 'Lifting a tier is not an exceeded budget.');
        $this->pass((int) (1.1 * self::BUDGET));
        $this->assertSame([1, 2, 1], array_column($received, 'tier'));
    }

    public function test_a_rising_tier_also_tells_the_continuity_event_but_a_lifted_one_does_not(): void
    {
        $this->configure('auto');
        $received = [];
        app(DomainEvents::class)->listen('watcher', ContinuityBreakRecorded::class, function (ContinuityBreakRecorded $event) use (&$received): void {
            $received[] = $event->payload();
        });
        $this->withModuleEnabled();

        $this->pass((int) (1.1 * self::BUDGET));
        $this->pass((int) (0.5 * self::BUDGET));

        $this->assertSame([['site_id' => null, 'kind' => 'storage_tier', 'scope' => 'all', 'data_from' => now('UTC')->toDateString()]], $received);
    }

    public function test_no_event_is_published_while_the_mode_is_not_applying_a_tier(): void
    {
        $this->configure('ask');
        $received = 0;
        app(DomainEvents::class)->listen('watcher', StorageBudgetExceeded::class, function () use (&$received): void {
            $received++;
        });
        $this->withModuleEnabled();

        $this->pass(2 * self::BUDGET);

        $this->assertSame(0, $received);
    }

    public function test_the_decision_is_a_pure_function_of_the_settings_and_the_size(): void
    {
        $now = Carbon::parse('2026-11-09 12:00:00', 'UTC');
        $auto = ['budget_bytes' => 100, 'mode' => 'auto', 'tier' => 0, 'tier_since' => null, 'agreed_tier' => 0];

        $this->assertSame(0, StorageBudget::decide($auto, 79, $now));
        $this->assertSame(0, StorageBudget::decide($auto, 99, $now));
        $this->assertSame(1, StorageBudget::decide($auto, 100, $now));
        $this->assertSame(1, StorageBudget::decide([...$auto, 'tier' => 1, 'tier_since' => '2026-11-09T01:00:00+00:00'], 150, $now));
        $this->assertSame(2, StorageBudget::decide([...$auto, 'tier' => 1, 'tier_since' => '2026-11-08T11:00:00+00:00'], 150, $now));
        $this->assertSame(2, StorageBudget::decide([...$auto, 'tier' => 2], 90, $now), 'Hysteresis: between 80% and 100% the tier holds.');
        $this->assertSame(0, StorageBudget::decide([...$auto, 'tier' => 2], 79, $now));
        $this->assertSame(4, StorageBudget::decide([...$auto, 'tier' => 4, 'tier_since' => '2020-01-01T00:00:00+00:00'], 900, $now));
        $this->assertSame(0, StorageBudget::decide([...$auto, 'mode' => 'off', 'tier' => 3], 900, $now));
        $this->assertSame(0, StorageBudget::decide([...$auto, 'budget_bytes' => null], 900, $now));
        $this->assertSame(0, StorageBudget::decide([...$auto, 'mode' => 'ask'], 900, $now));
        $this->assertSame(1, StorageBudget::decide([...$auto, 'mode' => 'ask', 'agreed_tier' => 1], 900, $now));
    }

    public function test_it_is_a_dedicated_hourly_command_that_does_not_overlap(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command, 'perysee:storage-budget'));
        $this->assertNotNull($event);
        $this->assertSame('0 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    /** The bus delivers to enabled modules only. */
    private function withModuleEnabled(): void
    {
        $modules = $this->installationDirectory.'/modules';
        @mkdir($modules);
        @mkdir($modules.'/watcher');
        file_put_contents($modules.'/watcher/module.json', json_encode(['id' => 'watcher', 'name' => 'Watcher', 'version' => '1.0.0', 'core' => '0.1.0']));
        config(['perysee.modules_path' => $modules]);
        app()->forgetInstance(ModuleRegistry::class);
        app(ModuleRegistry::class)->enable('watcher', acceptRisk: true);
    }
}
