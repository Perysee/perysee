<?php

namespace Tests\Feature;

use App\Installation\InstallationState;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SilentSiteIndicatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
    }

    private function site(User $user, string $name, ?\DateTimeInterface $lastEvent, bool $collected = true): Website
    {
        $site = Website::factory()->create(['name' => $name, 'first_collected_at' => $collected ? now('UTC')->subDays(40) : null]);
        $site->members()->attach($user, ['role' => 'owner']);
        if ($lastEvent) {
            DB::table('events')->insert(['website_id' => $site->id, 'received_at' => $lastEvent->format('Y-m-d H:i:s.v'), 'event_type' => 1, 'path' => '/', 'consent_given' => true]);
            DB::table('events')->insert(['website_id' => $site->id, 'received_at' => (clone $lastEvent)->modify('-2 days')->format('Y-m-d H:i:s.v'), 'event_type' => 1, 'path' => '/old', 'consent_given' => true]);
        }

        return $site;
    }

    public function test_the_site_list_flags_quiet_sites_and_links_to_the_check(): void
    {
        $user = User::factory()->create();
        $active = $this->site($user, 'A active', now('UTC')->subHours(2));
        $quiet = $this->site($user, 'B quiet', now('UTC')->subDays(5));
        $new = $this->site($user, 'C new', null, collected: false);
        $purged = $this->site($user, 'D purged', null);

        $this->actingAs($user)->get('/sites')->assertInertia(fn (Assert $page) => $page
            ->where('websites.data.0.id', $active->id)->where('websites.data.0.silent', false)->where('websites.data.0.last_event_at', fn ($at) => $at !== null)
            ->where('websites.data.1.id', $quiet->id)->where('websites.data.1.silent', true)->where('websites.data.1.verify_url', '/sites/'.$quiet->id.'/tracking/verify')
            ->where('websites.data.2.id', $new->id)->where('websites.data.2.silent', false)->where('websites.data.2.last_event_at', null)
            ->where('websites.data.3.id', $purged->id)->where('websites.data.3.silent', true));
    }

    public function test_the_threshold_is_configurable(): void
    {
        $user = User::factory()->create();
        $this->site($user, 'Quiet', now('UTC')->subDays(5));
        config(['perysee.silent_site_days' => 10]);

        $this->actingAs($user)->get('/sites')->assertInertia(fn (Assert $page) => $page->where('websites.data.0.silent', false));
    }

    public function test_the_dashboard_carries_the_same_flag(): void
    {
        $user = User::factory()->create();
        $this->site($user, 'Quiet', now('UTC')->subDays(5));

        $this->actingAs($user)->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('sites.data.0.silent', true));
    }

    public function test_a_page_of_twenty_sites_costs_one_grouped_query(): void
    {
        $user = User::factory()->create();
        foreach (range(1, 20) as $i) {
            $this->site($user, sprintf('Site %02d', $i), now('UTC')->subHours($i));
        }
        $this->actingAs($user);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });
        $this->get('/sites')->assertOk();

        $this->assertCount(1, array_filter($queries, fn (string $sql): bool => str_contains($sql, 'max(received_at)')), implode(' | ', $queries));
        $this->assertCount(0, array_filter($queries, fn (string $sql): bool => str_contains($sql, 'from "events"') && ! str_contains($sql, 'max(')));
    }

    public function test_an_empty_list_asks_nothing_of_the_events(): void
    {
        $queries = [];
        $this->actingAs(User::factory()->create());
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });
        $this->get('/sites')->assertOk();

        $this->assertEmpty(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'events')));
    }
}
