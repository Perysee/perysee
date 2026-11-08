<?php

namespace Tests\Feature;

use App\Analytics\InstanceHealth;
use App\Installation\InstallationState;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StorageQuotaSettingTest extends TestCase
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

    public function test_an_administrator_sets_the_quota_in_mebibytes_and_it_is_kept_in_bytes(): void
    {
        $this->actingAs($this->admin())->put('/health/storage', ['storage_quota_mib' => 2048])->assertRedirect()->assertSessionHas('status');

        $this->assertSame(2048 * 1048576, app(InstanceHealth::class)->get('storage_quota_bytes'));
    }

    public function test_an_empty_value_removes_the_quota(): void
    {
        app(InstanceHealth::class)->put('storage_quota_bytes', 1048576);

        $this->actingAs($this->admin())->put('/health/storage', ['storage_quota_mib' => ''])->assertSessionHasNoErrors();

        $this->assertNull(app(InstanceHealth::class)->get('storage_quota_bytes'));
        $this->assertSame(0, DB::table('analytics_state')->where('name', 'storage_quota_bytes')->count());
    }

    public function test_invalid_quotas_are_refused_and_change_nothing(): void
    {
        app(InstanceHealth::class)->put('storage_quota_bytes', 1048576);
        $this->actingAs($this->admin());

        foreach ([0, -5, 'abc', 1.5, 16777217, [1]] as $value) {
            $this->put('/health/storage', ['storage_quota_mib' => $value])->assertSessionHasErrors('storage_quota_mib');
        }

        $this->assertSame(1048576, app(InstanceHealth::class)->get('storage_quota_bytes'));
    }

    public function test_the_largest_accepted_quota_is_sixteen_tebibytes(): void
    {
        $this->actingAs($this->admin())->put('/health/storage', ['storage_quota_mib' => 16777216])->assertSessionHasNoErrors();

        $this->assertSame(16777216 * 1048576, app(InstanceHealth::class)->get('storage_quota_bytes'));
    }

    public function test_only_administrators_can_change_it(): void
    {
        $this->actingAs(User::factory()->create())->put('/health/storage', ['storage_quota_mib' => 10])->assertForbidden();

        $this->assertNull(app(InstanceHealth::class)->get('storage_quota_bytes'));
    }

    public function test_the_health_page_carries_the_quota_and_the_projection(): void
    {
        app(InstanceHealth::class)->put('storage_quota_bytes', 1048576 * 100);

        $this->actingAs($this->admin())->get('/health')->assertInertia(fn (Assert $page) => $page
            ->where('health.storage_quota_bytes', 1048576 * 100)
            ->where('health.storage_projection.status', 'unavailable')
            ->where('health.storage_projection.days', null)
            ->where('links', fn ($links) => isset($links['health.storage'])));
    }
}
