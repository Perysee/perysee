<?php

namespace Tests\Feature;

use App\Installation\InstallationState;
use App\Models\AccessLogEntry;
use App\Models\SiteInvitation;
use App\Models\User;
use App\Models\Website;
use App\Support\AccessLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AccessLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
    }

    public function test_addresses_are_truncated_to_a_24_for_ipv4_and_a_48_for_ipv6(): void
    {
        $this->assertSame('203.0.113.0', AccessLog::truncateIp('203.0.113.77'));
        $this->assertSame('2001:db8:abcd::', AccessLog::truncateIp('2001:db8:abcd:12:3456:789a:bcde:f012'));
        $this->assertSame('203.0.113.0', AccessLog::truncateIp('::ffff:203.0.113.77'));
        $this->assertNull(AccessLog::truncateIp('not an address'));
        $this->assertNull(AccessLog::truncateIp(null));
    }

    public function test_the_device_is_a_short_label_never_the_raw_user_agent(): void
    {
        $this->assertSame('Firefox on Linux', AccessLog::deviceLabel('Mozilla/5.0 (X11; Linux x86_64; rv:130.0) Gecko/20100101 Firefox/130.0'));
        $this->assertSame('Chrome on macOS', AccessLog::deviceLabel('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36'));
        $this->assertSame('Edge on Windows', AccessLog::deviceLabel('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/129.0 Safari/537.36 Edg/129.0'));
        $this->assertSame('Safari on iOS', AccessLog::deviceLabel('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1'));
        $this->assertSame('Unknown device', AccessLog::deviceLabel(null));
    }

    public function test_a_successful_sign_in_is_logged_with_a_truncated_address_and_a_label(): void
    {
        $user = User::factory()->create();

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.23'])->withHeaders(['User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64; rv:130.0) Gecko/20100101 Firefox/130.0'])
            ->post('/login', ['email' => $user->email, 'password' => 'password']);

        $entry = AccessLogEntry::firstOrFail();
        $this->assertSame('login.success', $entry->event);
        $this->assertSame($user->id, $entry->user_id);
        $this->assertSame('198.51.100.0', $entry->ip);
        $this->assertSame('Firefox on Linux', $entry->device);
        $this->assertSame('password', $entry->context['method']);
        $this->assertNull($entry->email_tried);
    }

    public function test_a_failed_sign_in_for_a_known_account_keeps_the_address_and_for_an_unknown_one_nothing(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'incorrect']);
        $this->post('/login', ['email' => 'Stranger@Example.Test', 'password' => 'pasted-secret-by-mistake']);

        [$known, $unknown] = [AccessLogEntry::where('user_id', $user->id)->firstOrFail(), AccessLogEntry::whereNull('user_id')->firstOrFail()];
        $this->assertSame('login.failed', $known->event);
        $this->assertSame($user->email, $known->email_tried);
        $this->assertSame('login.failed', $unknown->event);
        $this->assertNull($unknown->email_tried);
        $this->assertStringNotContainsStringIgnoringCase('stranger', json_encode($unknown->getAttributes()));
        $this->assertStringNotContainsString('pasted-secret', json_encode(AccessLogEntry::all()->toArray()));
    }

    public function test_a_visitor_cannot_fill_the_journal_with_failed_attempts(): void
    {
        foreach (range(1, 25) as $n) {
            $this->post('/login', ['email' => 'nobody'.$n.'@example.test', 'password' => 'incorrect']);
        }
        $this->assertSame(10, AccessLogEntry::whereNull('user_id')->where('event', 'login.failed')->count());

        // A known account is capped too, whatever the number of sign-in attempts spread over time.
        $user = User::factory()->create();
        foreach (range(1, 8) as $round) {
            foreach (range(1, 5) as $ignored) {
                $this->post('/login', ['email' => $user->email, 'password' => 'incorrect']);
            }
            $this->travel(61)->seconds();
        }
        $this->assertSame(30, AccessLogEntry::where('user_id', $user->id)->where('event', 'login.failed')->count());
    }

    public function test_a_throttled_attempt_is_not_logged_again(): void
    {
        $user = User::factory()->create();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'incorrect']);
        }
        $this->post('/login', ['email' => $user->email, 'password' => 'incorrect'])->assertStatus(429);

        $this->assertSame(5, AccessLogEntry::where('event', 'login.failed')->count());
    }

    public function test_accepting_an_invitation_is_logged_without_the_token(): void
    {
        $site = Website::factory()->create();
        $owner = User::factory()->create();
        $site->members()->attach($owner, ['role' => 'owner']);
        [, $token] = SiteInvitation::issue($site, 'invitee@example.com', 'viewer', $owner);

        $this->post('/invitations/'.$token, ['name' => 'Invitee', 'password' => 'a-long-enough-password'])->assertRedirect();

        $entry = AccessLogEntry::where('event', 'invitation.accepted')->firstOrFail();
        $this->assertSame(User::where('email', 'invitee@example.com')->value('id'), $entry->user_id);
        $this->assertSame($site->id, $entry->context['site_id']);
        $this->assertStringNotContainsString($token, json_encode($entry->toArray()));
    }

    public function test_creating_a_share_link_is_logged_without_the_link(): void
    {
        $site = Website::factory()->create();
        $owner = User::factory()->create();
        $site->members()->attach($owner, ['role' => 'owner']);

        $response = $this->actingAs($owner)->post('/sites/'.$site->id.'/sharing', ['sections' => ['overview']]);

        $url = session('share_created.url');
        $entry = AccessLogEntry::where('event', 'share.created')->firstOrFail();
        $this->assertSame($owner->id, $entry->user_id);
        $this->assertSame($site->id, $entry->context['site_id']);
        $this->assertStringNotContainsString(basename($url), json_encode($entry->toArray()));
        $response->assertRedirect();
    }

    public function test_a_user_sees_only_their_own_entries_newest_first(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        AccessLogEntry::create(['user_id' => $me->id, 'event' => 'login.success', 'created_at' => now()->subMinute()]);
        AccessLogEntry::create(['user_id' => $other->id, 'event' => 'login.success', 'created_at' => now()]);
        AccessLogEntry::create(['user_id' => $me->id, 'event' => 'twofactor.enabled', 'created_at' => now()]);

        $this->actingAs($me)->get('/account/security')->assertInertia(fn (Assert $page) => $page
            ->has('accessEntries.data', 2)->where('accessEntries.data.0.event', 'twofactor.enabled')->where('accessEntries.data.1.event', 'login.success'));
    }

    public function test_an_administrator_sees_the_instance_fifty_per_page_and_others_get_403(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $member = User::factory()->create();
        for ($index = 0; $index < 60; $index++) {
            AccessLogEntry::create(['user_id' => $index % 2 ? $member->id : null, 'event' => 'login.failed', 'created_at' => now()]);
        }

        $this->actingAs($member)->get('/settings/security')->assertForbidden();
        $this->actingAs($admin)->get('/settings/security')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('screen', 'settings.security')->has('instanceEntries.data', 50)->where('instanceEntries.total', 60)->where('retentionDays', 90));
        $this->get('/settings/security?log=2')->assertInertia(fn (Assert $page) => $page->has('instanceEntries.data', 10));
    }

    public function test_an_unknown_event_name_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AccessLog::record('anything.else', null);
    }

    public function test_the_log_has_no_route_to_edit_or_delete_an_entry(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $entry = AccessLogEntry::create(['user_id' => $admin->id, 'event' => 'login.success', 'created_at' => now()]);

        $this->actingAs($admin)->delete('/settings/security/'.$entry->id)->assertNotFound();
        $this->put('/account/security/'.$entry->id)->assertNotFound();
        $this->assertSame(1, AccessLogEntry::count());
    }

    public function test_entries_disappear_with_their_account(): void
    {
        $user = User::factory()->create();
        AccessLogEntry::create(['user_id' => $user->id, 'event' => 'login.success', 'created_at' => now()]);

        $user->delete();

        $this->assertSame(0, AccessLogEntry::count());
    }
}
