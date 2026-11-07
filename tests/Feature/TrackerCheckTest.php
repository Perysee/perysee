<?php

namespace Tests\Feature;

use App\Installation\InstallationState;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TrackerCheckTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
    }

    private function member(Website $site, string $role = 'owner'): User
    {
        $user = User::factory()->create();
        $site->members()->attach($user, ['role' => $role]);

        return $user;
    }

    private function event(Website $site, \DateTimeInterface $at, string $path = '/'): void
    {
        DB::table('events')->insert(['website_id' => $site->id, 'received_at' => $at->format('Y-m-d H:i:s.v'), 'event_type' => 1, 'path' => $path, 'consent_given' => true]);
    }

    private function diagnostic(Website|int $site, string $cause, int $hits = 1, ?string $host = null, ?\DateTimeInterface $at = null): void
    {
        $at ??= now('UTC');
        $bucket = (clone $at)->setTime((int) $at->format('H'), 0, 0)->format('Y-m-d H:i:s');
        DB::table('collection_diagnostics')->insert(['website_id' => $site instanceof Website ? $site->id : $site, 'bucket_start' => $bucket, 'cause' => $cause, 'hits' => $hits, 'sample_host' => $host, 'first_at' => $at->format('Y-m-d H:i:s'), 'last_at' => $at->format('Y-m-d H:i:s')]);
    }

    private function check(Website $site, User $user): Assert|TestResponse
    {
        return $this->actingAs($user)->get('/sites/'.$site->id.'/tracking/verify');
    }

    private function codes(Website $site, User $user): array
    {
        $codes = [];
        $this->check($site, $user)->assertOk()->assertInertia(function (Assert $page) use (&$codes): void {
            $codes = array_column($page->toArray()['props']['check']['diagnoses'], 'code');
        });

        return $codes;
    }

    public function test_a_site_that_never_received_anything_is_waiting_with_the_install_advice(): void
    {
        $site = Website::factory()->create();
        $user = $this->member($site);

        $this->check($site, $user)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('screen', 'sites.verify')->where('check.state', 'waiting')->where('check.last_event', null)->where('check.events_24h.count', 0)
            ->where('check.diagnoses.0.code', 'waiting')->where('check.diagnoses.0.level', 'error')
            ->where('snippet', fn (string $snippet) => str_contains($snippet, 'data-site="'.$site->public_id.'"'))
            ->where('canUpdate', true));
    }

    public function test_a_sessions_site_that_never_received_anything_is_told_about_the_cookie(): void
    {
        $site = Website::factory()->create(['tracking_mode' => 'sessions']);

        $this->assertSame(['waiting_sessions'], $this->codes($site, $this->member($site)));
    }

    public function test_the_last_event_and_the_volume_of_the_last_day_are_shown(): void
    {
        $site = Website::factory()->create(['first_collected_at' => now('UTC')->subDay()]);
        $this->event($site, now('UTC')->subHours(30), '/old');
        $this->event($site, now('UTC')->subHours(2), '/pricing');
        $this->event($site, now('UTC')->subMinutes(5), '/contact');
        $other = Website::factory()->create();
        $this->event($other, now('UTC'), '/elsewhere');

        $this->check($site, $this->member($site))->assertInertia(fn (Assert $page) => $page
            ->where('check.state', 'receiving')->where('check.last_event.path', '/contact')->where('check.last_event.type', 'pageview')
            ->where('check.events_24h', ['count' => 2, 'capped' => false])
            ->where('check.diagnoses.0.code', 'receiving')->where('check.diagnoses.0.level', 'success'));
    }

    public function test_the_volume_is_capped_instead_of_scanned(): void
    {
        $site = Website::factory()->create();
        $rows = [];
        for ($i = 0; $i < 10005; $i++) {
            $rows[] = ['website_id' => $site->id, 'received_at' => now('UTC')->subSeconds($i % 600)->format('Y-m-d H:i:s.v'), 'event_type' => 1, 'path' => '/', 'consent_given' => true];
        }
        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('events')->insert($chunk);
        }
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->check($site, $this->member($site))->assertInertia(fn (Assert $page) => $page->where('check.events_24h', ['count' => 10000, 'capped' => true]));
        $this->assertNotEmpty(array_filter($queries, fn (string $sql): bool => str_contains(strtolower($sql), 'limit 10001')));
    }

    public function test_a_site_with_no_event_for_the_threshold_is_silent(): void
    {
        $site = Website::factory()->create(['first_collected_at' => now('UTC')->subDays(30)]);
        $this->event($site, now('UTC')->subDays(4));
        $user = $this->member($site);

        $this->check($site, $user)->assertInertia(fn (Assert $page) => $page->where('check.state', 'silent')->where('check.diagnoses.0.code', 'silent')->where('check.diagnoses.0.title', 'No event for 3 days'));
        config(['perysee.silent_site_days' => 7]);
        $this->check($site, $user)->assertInertia(fn (Assert $page) => $page->where('check.state', 'receiving'));
    }

    public function test_a_site_whose_raw_events_were_purged_is_silent_not_new(): void
    {
        $site = Website::factory()->create(['first_collected_at' => now('UTC')->subDays(120)]);

        $this->assertSame(['silent'], $this->codes($site, $this->member($site)));
    }

    public function test_rejections_become_ordered_diagnoses_with_their_host(): void
    {
        $site = Website::factory()->create();
        $this->diagnostic($site, 'quota', 4);
        $this->diagnostic($site, 'domain_not_allowed', 7, 'www.example.org');
        $this->diagnostic($site, 'consent_missing', 2);
        $this->diagnostic($site, 'domain_not_allowed', 3, 'www.example.org', now('UTC')->subHour());

        $this->check($site, $this->member($site))->assertInertia(fn (Assert $page) => $page
            ->where('check.rejections.0.cause', 'domain_not_allowed')->where('check.rejections.0.hits', 10)->where('check.rejections.0.host', 'www.example.org')
            ->where('check.diagnoses.0.code', 'domain_not_allowed')->where('check.diagnoses.0.level', 'error')
            ->where('check.diagnoses.0.message', fn (string $message) => str_contains($message, '"www.example.org"'))
            ->where('check.diagnoses.0.action', fn (string $action) => str_contains($action, 'Add exactly "www.example.org"'))
            ->where('check.diagnoses.1.code', 'consent_missing')
            ->where('check.diagnoses.2.code', 'quota'));
    }

    public function test_refusals_next_to_accepted_events_are_only_warnings_and_path_exclusions_are_information(): void
    {
        $site = Website::factory()->create(['first_collected_at' => now('UTC')]);
        $this->event($site, now('UTC'));
        $this->diagnostic($site, 'consent_missing', 2);
        $this->diagnostic($site, 'path_excluded', 9);

        $this->check($site, $this->member($site))->assertInertia(fn (Assert $page) => $page
            ->where('check.diagnoses.0.level', 'warning')->where('check.diagnoses.1.code', 'path_excluded')->where('check.diagnoses.1.level', 'info'));
    }

    public function test_old_rejections_do_not_count(): void
    {
        $site = Website::factory()->create();
        $this->diagnostic($site, 'quota', 4, null, now('UTC')->subDays(3));

        $this->assertSame(['waiting'], $this->codes($site, $this->member($site)));
    }

    public function test_instance_wide_unknown_keys_are_shown_only_when_they_come_from_this_sites_domain(): void
    {
        $site = Website::factory()->create(['domain' => 'mine.example', 'allowed_domains' => ['mine.example']]);
        $this->diagnostic(0, 'unknown_site', 5, 'mine.example');
        $this->diagnostic(0, 'invalid_payload', 5);
        $stranger = Website::factory()->create(['domain' => 'theirs.example', 'allowed_domains' => ['theirs.example']]);
        $this->diagnostic(0, 'unknown_site', 5, 'theirs.example', now('UTC')->subHour());

        $this->assertSame(['unknown_site'], $this->codes($site, $this->member($site)));
        $this->check($site, $this->member($site))->assertInertia(fn (Assert $page) => $page->where('check.diagnoses.0.host', 'mine.example'));
        $this->assertNotContains('mine.example', array_column(json_decode(json_encode($this->check($stranger, $this->member($stranger))->viewData('page')['props']['check']['rejections']), true), 'host'));
    }

    public function test_a_recent_test_without_a_real_event_says_the_collector_works(): void
    {
        $site = Website::factory()->create();
        $this->diagnostic($site, 'probe_ok', 1);

        $this->check($site, $this->member($site))->assertInertia(fn (Assert $page) => $page->where('check.probe.at', fn ($at) => $at !== null)->where('check.diagnoses.0.code', 'probe_only'));
    }

    public function test_a_real_event_after_the_test_clears_the_probe_advice(): void
    {
        $site = Website::factory()->create(['first_collected_at' => now('UTC')]);
        $this->diagnostic($site, 'probe_ok', 1, null, now('UTC')->subHours(3));
        $this->event($site, now('UTC')->subMinute());

        $this->assertSame(['receiving'], $this->codes($site, $this->member($site)));
    }

    public function test_a_viewer_can_read_but_not_run_the_test(): void
    {
        $site = Website::factory()->create();
        $viewer = $this->member($site, 'viewer');

        $this->check($site, $viewer)->assertOk()->assertInertia(fn (Assert $page) => $page->where('canUpdate', false));
        $this->actingAs($viewer)->postJson('/sites/'.$site->id.'/tracking/probe')->assertForbidden();
    }

    public function test_non_members_and_unattached_administrators_have_no_access(): void
    {
        $site = Website::factory()->create();
        $this->check($site, User::factory()->create())->assertForbidden();
        $this->check($site, User::factory()->create(['is_admin' => true]))->assertForbidden();
        $this->postJson('/sites/'.$site->id.'/tracking/probe')->assertForbidden();
        auth()->logout();
        $this->get('/sites/'.$site->id.'/tracking/verify')->assertRedirect('/login');
    }

    public function test_a_site_being_deleted_cannot_be_checked(): void
    {
        $site = Website::factory()->create(['deleting_at' => now('UTC')]);

        $this->check($site, $this->member($site))->assertNotFound();
    }

    public function test_the_page_uses_relative_links_and_the_check_is_a_partial_reload_prop(): void
    {
        $site = Website::factory()->create();
        $user = $this->member($site);

        $this->check($site, $user)->assertInertia(fn (Assert $page) => $page
            ->where('siteLinks.verify', '/sites/'.$site->id.'/tracking/verify')->where('siteLinks.probe', '/sites/'.$site->id.'/tracking/probe')
            ->where('siteLinks.tracking', '/sites/'.$site->id.'/tracking')->where('collectorRoot', fn (string $root) => str_starts_with($root, 'http')));
        $response = $this->actingAs($user)->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'Workspace', 'X-Inertia-Partial-Data' => 'check', 'X-Requested-With' => 'XMLHttpRequest', 'X-Inertia-Version' => Inertia::getVersion()])->get('/sites/'.$site->id.'/tracking/verify')->assertOk();
        $this->assertArrayHasKey('check', $response->json('props'));
        $this->assertArrayNotHasKey('snippet', $response->json('props'));
    }

    public function test_reading_the_check_writes_nothing_and_costs_few_queries(): void
    {
        $site = Website::factory()->create();
        $user = $this->member($site);
        $this->actingAs($user)->get('/sites/'.$site->id.'/tracking/verify')->assertOk();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });
        $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'Workspace', 'X-Inertia-Partial-Data' => 'check', 'X-Requested-With' => 'XMLHttpRequest', 'X-Inertia-Version' => Inertia::getVersion()])->get('/sites/'.$site->id.'/tracking/verify')->assertOk();

        // The open-sessions registry (two-factor) may stamp a request that starts a new session; only the check itself must not write.
        $this->assertEmpty(array_filter($queries, fn (string $sql): bool => (bool) preg_match('/^(insert|update|delete)/', $sql) && ! str_contains($sql, 'user_sessions')));
        $checkQueries = array_filter($queries, fn (string $sql): bool => str_contains($sql, '"events"') || str_contains($sql, '`events`') || str_contains($sql, 'collection_diagnostics'));
        $this->assertLessThanOrEqual(4, count($checkQueries), implode(' | ', $queries));
    }
}
