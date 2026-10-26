<?php

namespace Tests\Feature;

use App\Analytics\Aggregation\Aggregator;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AnalyticsUpgradeTest extends TestCase
{
    use DatabaseMigrations;

    public function test_upgrade_preserves_accounts_sites_key_and_previously_confirmed_events(): void
    {
        $this->artisan('migrate:rollback', ['--step' => 4, '--force' => true])->assertSuccessful();
        $key = config('app.key');
        $user = User::factory()->create();
        $password = $user->password;
        $site = Website::factory()->create(['timezone' => 'Europe/Paris']);
        $site->members()->attach($user, ['role' => 'owner']);
        $id = DB::table('events')->insertGetId(['website_id' => $site->id, 'received_at' => '2026-10-24 10:00:00', 'path' => '/existing', 'consent_given' => true]);
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $this->assertSame($password, $user->fresh()->password);
        $this->assertSame($key, config('app.key'));
        $this->assertSame($site->public_id, $site->fresh()->public_id);
        $this->assertNotNull($site->fresh()->first_collected_at);
        $this->assertDatabaseHas('events', ['id' => $id, 'processed_at' => null, 'dimensions_available' => false, 'tracking_mode' => null]);
        $this->assertSame(1, app(Aggregator::class)->run(1));
        $this->assertDatabaseHas('stats_daily', ['website_id' => $site->id, 'pageviews' => 1, 'unknown_pageviews' => 1]);
        $this->assertSame(0, DB::table('stats_dimension_daily')->where('dimension_type', 'source')->count());
        RefreshDatabaseState::$migrated = false;
    }
}
