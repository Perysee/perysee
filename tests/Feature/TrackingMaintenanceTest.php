<?php

namespace Tests\Feature;

use App\Installation\InstallationState;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TrackingMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_retention_is_bounded_and_preserves_recent_events(): void
    {
        $this->freezeTime();
        $site = Website::factory()->create();
        foreach ([now()->subDays(100), now()->subDays(99), now()] as $date) {
            DB::table('events')->insert(['website_id' => $site->id, 'path' => '/', 'received_at' => $date, 'consent_given' => true, 'processed_at' => now()]);
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

    public function test_old_guide_urls_redirect_to_the_configured_documentation_site(): void
    {
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
        config(['perysee.docs_url' => 'https://docs.example.com/']);
        $this->actingAs(User::factory()->create());
        $this->get('/guides/application')->assertRedirect('https://docs.example.com/getting-started/shared-hosting');
        $this->get('/guides/tracking')->assertRedirect('https://docs.example.com/tracking/installation');
        $this->get('/guides/privacy')->assertRedirect('https://docs.example.com/tracking/privacy');
        $this->get('/guides/unknown')->assertNotFound();
        $this->get('/sites')->assertOk()->assertInertia(fn (Assert $page) => $page->where('docsUrl', 'https://docs.example.com'));
    }
}
