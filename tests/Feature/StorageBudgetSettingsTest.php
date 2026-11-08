<?php

namespace Tests\Feature;

use App\Analytics\InstanceHealth;
use App\Analytics\StorageBudget;
use App\Analytics\StorageMeter;
use App\Installation\InstallationState;
use App\Models\AccessLogEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StorageBudgetSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function health(): InstanceHealth
    {
        return app(InstanceHealth::class);
    }

    public function test_an_administrator_sets_a_budget_in_mebibytes_and_a_mode(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put('/health/storage/budget', ['storage_budget_mib' => 512, 'storage_mode' => 'ask'])->assertRedirect()->assertSessionHas('status');

        $settings = app(StorageBudget::class)->settings();
        $this->assertSame(512 * 1048576, $settings['budget_bytes']);
        $this->assertSame('ask', $settings['mode']);
        $entry = AccessLogEntry::where('event', 'storage.settings')->sole();
        $this->assertSame($admin->id, $entry->user_id);
        $this->assertSame(['mode' => 'ask', 'budget_mib' => 512], $entry->context);
    }

    public function test_choosing_auto_is_written_to_the_access_log_and_an_unchanged_save_is_not(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->put('/health/storage/budget', ['storage_budget_mib' => 100, 'storage_mode' => 'auto'])->assertSessionHasNoErrors();
        $this->put('/health/storage/budget', ['storage_budget_mib' => 100, 'storage_mode' => 'auto'])->assertSessionHasNoErrors();

        $this->assertSame(1, AccessLogEntry::where('event', 'storage.settings')->count());
        $this->assertSame('auto', AccessLogEntry::where('event', 'storage.settings')->sole()->context['mode']);
    }

    public function test_invalid_values_are_refused_and_change_nothing(): void
    {
        $this->health()->put('storage_budget_bytes', 1048576);
        $this->health()->put('storage_mode', 'off');
        $this->actingAs($this->admin());

        foreach ([[0, 'off'], [-1, 'off'], ['abc', 'off'], [1.5, 'off'], [16777217, 'off'], [10, 'sometimes'], [10, ''], [10, ['auto']]] as [$budget, $mode]) {
            $this->put('/health/storage/budget', ['storage_budget_mib' => $budget, 'storage_mode' => $mode])->assertSessionHasErrors();
        }
        $this->put('/health/storage/budget', ['storage_budget_mib' => '', 'storage_mode' => 'auto'])->assertSessionHasErrors('storage_mode');

        $settings = app(StorageBudget::class)->settings();
        $this->assertSame(1048576, $settings['budget_bytes']);
        $this->assertSame('off', $settings['mode']);
        $this->assertSame(0, AccessLogEntry::where('event', 'storage.settings')->count());
    }

    public function test_an_empty_budget_with_the_mode_off_removes_the_budget(): void
    {
        $this->health()->put('storage_budget_bytes', 1048576);
        $this->health()->put('storage_mode', 'auto');

        $this->actingAs($this->admin())->put('/health/storage/budget', ['storage_budget_mib' => '', 'storage_mode' => 'off'])->assertSessionHasNoErrors();

        $this->assertNull(app(StorageBudget::class)->settings()['budget_bytes']);
    }

    public function test_only_administrators_can_change_it(): void
    {
        $this->actingAs(User::factory()->create())->put('/health/storage/budget', ['storage_budget_mib' => 10, 'storage_mode' => 'auto'])->assertForbidden();
        $this->put('/health/storage/agreement', ['tier' => 1])->assertForbidden();

        $this->assertNull(app(StorageBudget::class)->settings()['budget_bytes']);
    }

    private function overBudgetInAskMode(): void
    {
        $this->health()->put('storage_budget_bytes', 1000);
        $this->health()->put('storage_mode', 'ask');
        $this->app->instance(StorageMeter::class, new class extends StorageMeter
        {
            public function totalBytes(): ?int
            {
                return 1500;
            }
        });
        $this->artisan('perysee:storage-budget')->assertSuccessful();
    }

    public function test_the_agreement_is_validated_and_recorded_in_the_access_log(): void
    {
        $this->overBudgetInAskMode();
        $admin = $this->admin();

        $this->actingAs($admin)->put('/health/storage/agreement', ['tier' => 2])->assertSessionHasErrors('tier');
        $this->put('/health/storage/agreement', ['tier' => 9])->assertSessionHasErrors('tier');
        $this->put('/health/storage/agreement', [])->assertSessionHasErrors('tier');
        $this->assertSame(0, AccessLogEntry::where('event', 'storage.agreed')->count());
        $this->assertSame(0, $this->health()->effectiveRetention()['tier']);

        $this->put('/health/storage/agreement', ['tier' => 1])->assertSessionHasNoErrors()->assertSessionHas('status');

        $this->assertSame(['tier' => 1], AccessLogEntry::where('event', 'storage.agreed')->sole()->context);
        $this->artisan('perysee:storage-budget')->assertSuccessful();
        $this->assertSame(1, $this->health()->effectiveRetention()['tier']);
    }

    public function test_the_health_page_shows_the_tier_the_mode_and_the_budget(): void
    {
        $this->overBudgetInAskMode();

        $this->actingAs($this->admin())->get('/health')->assertInertia(fn (Assert $page) => $page
            ->component('Workspace')->where('screen', 'health')
            ->where('health.storage_budget.budget_bytes', 1000)
            ->where('health.storage_budget.mode', 'ask')
            ->where('health.storage_budget.tier', 0)
            ->where('health.storage_budget.proposal.tier', 1)
            ->where('health.storage_budget.proposal.raw_days', 30)
            ->where('health.storage_budget.effective.raw', 90)
            ->where('links', fn ($links) => isset($links['health.storage.budget'], $links['health.storage.agreement'])));

        $this->health()->put('storage_mode', 'auto');
        $this->artisan('perysee:storage-budget')->assertSuccessful();

        $this->get('/health')->assertInertia(fn (Assert $page) => $page
            ->where('health.storage_budget.tier', 1)
            ->where('health.storage_budget.mode', 'auto')
            ->where('health.storage_budget.effective.raw', 30)
            ->where('health.storage_budget.caps.raw_days', 30)
            ->where('health.storage_budget.proposal', null)
            ->where('health.raw_retention_days', 90));
    }

    public function test_the_health_page_without_a_budget_says_so(): void
    {
        $this->actingAs($this->admin())->get('/health')->assertInertia(fn (Assert $page) => $page
            ->where('health.storage_budget.budget_bytes', null)
            ->where('health.storage_budget.mode', 'off')
            ->where('health.storage_budget.tier', 0)
            ->where('health.storage_budget.ratio_percent', null));
    }
}
