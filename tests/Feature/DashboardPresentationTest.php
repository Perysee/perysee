<?php

namespace Tests\Feature;

use App\Installation\InstallationState;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardPresentationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
    }

    public function test_overview_keeps_the_period_when_opening_breakdowns_and_exports(): void
    {
        $site = Website::factory()->create();
        $user = User::factory()->create();
        $site->members()->attach($user, ['role' => 'viewer']);
        DB::table('stats_daily')->insert(['website_id' => $site->id, 'stat_date' => '2026-10-24', 'pageviews' => 12]);
        DB::table('stats_dimension_daily')->insert(['website_id' => $site->id, 'stat_date' => '2026-10-24', 'dimension_type' => 'source', 'dimension_hash' => hash('sha256', 'newsletter.example'), 'dimension_value' => 'newsletter.example', 'metric_count' => 8]);
        $response = $this->actingAs($user)->get('/sites/'.$site->id.'?from=2026-10-24&to=2026-10-24');
        $response->assertOk()->assertSee('newsletter.example')->assertSee('12');
        $response->assertInertia(fn (Assert $page) => $page->where('period.from', '2026-10-24')->where('period.to', '2026-10-24')->where('siteLinks.sources', url('/sites/'.$site->id.'/sources'))->where('report.totals.pageviews', 12)->where('report.availability.sessions', 'unavailable')->where('canUpdate', false));
    }

    public function test_reception_page_keeps_recent_events_separate_from_historical_totals(): void
    {
        $site = Website::factory()->create();
        $user = User::factory()->create();
        $site->members()->attach($user, ['role' => 'owner']);
        DB::table('stats_daily')->insert(['website_id' => $site->id, 'stat_date' => now('UTC')->toDateString(), 'pageviews' => 91827]);
        DB::table('events')->insert(['website_id' => $site->id, 'received_at' => now('UTC'), 'path' => '/just-arrived', 'consent_given' => true]);
        $this->actingAs($user)->get('/sites/'.$site->id.'/realtime')->assertOk()->assertInertia(fn (Assert $page) => $page->where('section', 'realtime')->where('recent.0.path', '/just-arrived')->where('receptionTotals.events', 1)->where('receptionTotals.pageviews', 1));
    }

    public function test_workspace_cards_show_only_member_sites_with_real_totals(): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        $site = Website::factory()->create(['name' => 'My journal']);
        $site->members()->attach($user, ['role' => 'viewer']);
        Website::factory()->create(['name' => 'Hidden client']);
        DB::table('stats_daily')->insert(['website_id' => $site->id, 'stat_date' => now('UTC')->toDateString(), 'pageviews' => 42]);
        $this->actingAs($user)->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page->where('screen', 'dashboard')->has('sites.data', 1)->where('sites.data.0.name', 'My journal')->where('summaries.'.$site->id.'.totals.pageviews', 42))->assertDontSee('Hidden client');
    }
}
