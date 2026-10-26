<?php

namespace Tests\Feature;

use App\Installation\InstallationState;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WorkspaceCompletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
    }

    public function test_deleting_sites_cannot_be_opened_in_tracking_or_settings(): void
    {
        $user = User::factory()->create();
        $site = Website::factory()->create(['deleting_at' => now()]);
        $site->members()->attach($user, ['role' => 'owner']);
        $this->actingAs($user)->get('/sites/'.$site->id.'/tracking')->assertNotFound();
        $this->get('/sites/'.$site->id.'/edit')->assertNotFound();
    }

    public function test_dashboard_exposes_each_sites_actual_local_dates(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 26)->setTime(0, 15));
        $user = User::factory()->create();
        $site = Website::factory()->create(['timezone' => 'America/Los_Angeles']);
        $site->members()->attach($user, ['role' => 'viewer']);
        $this->actingAs($user)->get('/dashboard?period=today')->assertInertia(fn (Assert $page) => $page
            ->where('summaries.'.$site->id.'.period.to', '2026-10-25')
            ->where('sitePeriods.'.$site->id.'.to', '2026-10-25'));
    }
}
