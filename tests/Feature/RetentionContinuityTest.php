<?php

namespace Tests\Feature;

use App\Analytics\InstanceHealth;
use App\Installation\InstallationState;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RetentionContinuityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
        $this->admin = User::factory()->create(['is_admin' => true]);
    }

    private function save(int $raw, int $stats): void
    {
        $this->actingAs($this->admin)->put('/health', ['raw_retention_days' => $raw, 'stats_retention_days' => $stats])->assertSessionHasNoErrors();
    }

    public function test_shortening_a_retention_records_a_break_and_lengthening_one_a_note(): void
    {
        $this->save(30, 1095);
        $this->save(30, 3650);

        $entries = DB::table('continuity_log')->orderBy('id')->get();
        $this->assertSame(['retention_change', 'retention_change'], $entries->pluck('kind')->all());
        $this->assertSame(['breaks', 'note'], $entries->pluck('severity')->all());
        $this->assertSame([null, null], $entries->pluck('website_id')->all());
        $this->assertSame(['all', 'all'], $entries->pluck('scope')->all());
        $this->assertSame($this->admin->id, (int) $entries[0]->actor_id);
        $this->assertSame(['setting' => 'raw_retention_days', 'from' => 90, 'to' => 30], json_decode($entries[0]->payload, true));
        $this->assertSame(['setting' => 'stats_retention_days', 'from' => 1095, 'to' => 3650], json_decode($entries[1]->payload, true));
        $this->assertSame(30, app(InstanceHealth::class)->get('raw_retention_days'));
    }

    public function test_saving_unchanged_retentions_records_nothing(): void
    {
        $this->save(90, 1095);
        $this->save(90, 1095);

        $this->assertSame(0, DB::table('continuity_log')->count());
    }

    public function test_both_retentions_changing_at_once_record_two_entries(): void
    {
        $this->save(60, 365);

        $this->assertSame(['raw_retention_days', 'stats_retention_days'], DB::table('continuity_log')->orderBy('id')->pluck('payload')->map(fn ($payload) => json_decode($payload, true)['setting'])->all());
        $this->assertSame(['breaks', 'breaks'], DB::table('continuity_log')->orderBy('id')->pluck('severity')->all());
    }
}
