<?php

namespace Tests\Feature;

use App\Installation\InstallationState;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TrackingMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_retention_is_bounded_and_preserves_recent_events(): void
    {
        $this->freezeTime();
        $site = Website::factory()->create();
        foreach ([now()->subDays(100), now()->subDays(99), now()] as $date) {
            DB::table('events')->insert(['website_id' => $site->id, 'path' => '/', 'received_at' => $date, 'consent_given' => true]);
        }
        $this->artisan('perysee:prune-tracking', ['--limit' => 1])->assertSuccessful();
        $this->assertDatabaseCount('events', 2);
        $this->artisan('perysee:prune-tracking')->assertSuccessful();
        $this->assertDatabaseCount('events', 1);
    }

    public function test_demo_page_is_only_available_locally_and_to_its_members(): void
    {
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
        $owner = User::factory()->create();
        $site = Website::factory()->create(['is_demo' => true]);
        $site->members()->attach($owner, ['role' => 'owner']);
        $this->actingAs($owner)->get('/demo/'.$site->id)->assertNotFound();
        $this->app->instance('env', 'local');
        $this->get('/demo/'.$site->id)->assertOk()->assertSee('Allow measurement');
        $this->actingAs(User::factory()->create())->get('/demo/'.$site->id)->assertForbidden();
    }

    public function test_documentation_is_available_without_executing_embedded_examples(): void
    {
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
        $this->actingAs(User::factory()->create())->get('/guides/tracking')->assertOk()->assertSee('Installer Lueur')->assertDontSee('<script defer crossorigin=', false);
        $this->get('/guides/privacy')->assertOk()->assertSee('JavaScript ou cookie');
        $this->get('/guides/unknown')->assertNotFound();
    }
}
