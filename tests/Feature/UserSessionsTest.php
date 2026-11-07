<?php

namespace Tests\Feature;

use App\Installation\InstallationState;
use App\Models\AccessLogEntry;
use App\Models\User;
use App\Models\UserSession;
use App\Support\UserSessions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\KeepsSessionCookie;
use Tests\TestCase;

class UserSessionsTest extends TestCase
{
    use KeepsSessionCookie, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
    }

    /** Another browser of the same account: a stored session payload plus its row in the list. */
    private function otherBrowser(User $user, string $device = 'Firefox on Linux'): UserSession
    {
        $id = Str::random(40);
        Session::getHandler()->write($id, 'payload-of-another-browser');

        return UserSession::create([
            'user_id' => $user->id, 'session_hash' => UserSessions::hash($id), 'session_id_enc' => Crypt::encryptString($id),
            'device' => $device, 'ip' => '203.0.113.0', 'created_at' => now(), 'last_seen_at' => now(),
        ]);
    }

    private function stored(UserSession $row): string
    {
        return Session::getHandler()->read(Crypt::decryptString($row->session_id_enc));
    }

    public function test_signing_in_adds_a_row_and_the_raw_session_id_is_nowhere_in_the_database(): void
    {
        $user = User::factory()->create();
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $id = session()->getId();

        $row = UserSession::where('user_id', $user->id)->firstOrFail();
        $this->assertSame(UserSessions::hash($id), $row->session_hash);
        $this->assertSame($id, Crypt::decryptString($row->session_id_enc));
        $this->assertStringNotContainsString($id, json_encode(DB::table('user_sessions')->get()->all()));
        $this->assertSame('Unknown device', $row->device);
    }

    public function test_the_list_marks_the_current_session_and_never_exposes_ids(): void
    {
        $user = User::factory()->create();
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $other = $this->otherBrowser($user);

        $response = $this->browser()->get('/account/security');

        $response->assertInertia(fn (Assert $page) => $page->has('openSessions', 2)
            ->where('openSessions', fn ($sessions) => collect($sessions)->where('current', true)->count() === 1 && collect($sessions)->where('id', $other->id)->first()['current'] === false)
            ->missing('openSessions.0.session_hash')->missing('openSessions.0.session_id_enc'));
        $this->assertStringNotContainsString(session()->getId(), $response->getContent());
    }

    public function test_revoking_a_session_destroys_it_and_is_logged(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $other = $this->otherBrowser($user);
        $this->assertNotSame('', $this->stored($other));
        $encrypted = $other->session_id_enc;

        $this->delete('/account/security/sessions/'.$other->id)->assertRedirect('/account/security');

        $this->assertNull(UserSession::find($other->id));
        $this->assertSame('', Session::getHandler()->read(Crypt::decryptString($encrypted)));
        $this->assertSame(1, AccessLogEntry::where('event', 'session.revoked')->where('user_id', $user->id)->count());
    }

    public function test_the_session_in_use_cannot_be_revoked_from_the_list(): void
    {
        $user = User::factory()->create();
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $current = UserSession::where('user_id', $user->id)->firstOrFail();

        $this->browser()->delete('/account/security/sessions/'.$current->id)->assertSessionHasErrors('session');

        $this->assertNotNull(UserSession::find($current->id));
    }

    public function test_the_session_of_another_account_is_a_404_and_stays_alive(): void
    {
        $user = User::factory()->create();
        $victim = $this->otherBrowser(User::factory()->create());

        $this->actingAs($user)->delete('/account/security/sessions/'.$victim->id)->assertNotFound();

        $this->assertNotNull(UserSession::find($victim->id));
        $this->assertNotSame('', $this->stored($victim));
    }

    public function test_revoking_all_the_others_spares_the_current_session_and_other_accounts(): void
    {
        $user = User::factory()->create();
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $first = $this->otherBrowser($user);
        $second = $this->otherBrowser($user);
        $stranger = $this->otherBrowser(User::factory()->create());

        $this->browser()->delete('/account/security/sessions')->assertRedirect('/account/security');

        $this->assertSame(1, UserSession::where('user_id', $user->id)->count());
        $this->assertNull(UserSession::find($first->id));
        $this->assertNull(UserSession::find($second->id));
        $this->assertNotNull(UserSession::find($stranger->id));
        $this->assertSame('', Session::getHandler()->read(Crypt::decryptString($first->session_id_enc)));
        $this->browser()->get('/sites')->assertOk();
    }

    public function test_a_revoke_is_bounded_per_action(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        for ($index = 0; $index < UserSessions::REVOKE_LIMIT + 3; $index++) {
            $this->otherBrowser($user);
        }

        $this->delete('/account/security/sessions');

        // The session of this request is registered too, so it is the 3 unrevoked ones plus the current one.
        $this->assertSame(4, UserSession::where('user_id', $user->id)->count());
    }

    public function test_the_last_seen_time_is_written_once_per_ten_minutes_not_on_every_request(): void
    {
        $user = User::factory()->create();
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'user_sessions')) {
                $queries[] = $query->sql;
            }
        });

        $this->browser()->get('/sites');
        $this->browser()->get('/sites');
        $this->assertSame([], $queries, 'No query against the list inside the window.');

        $this->travel(UserSessions::TOUCH_SECONDS + 1)->seconds();
        $before = UserSession::firstOrFail()->last_seen_at;
        $queries = [];
        $this->browser()->get('/sites');
        $this->assertNotEmpty($queries);
        $this->assertTrue(UserSession::firstOrFail()->last_seen_at->greaterThan($before));
        $queries = [];
        $this->browser()->get('/sites');
        $this->assertSame([], $queries);
    }

    public function test_a_session_that_existed_before_the_list_is_added_on_its_next_request(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/sites')->assertOk();

        $this->assertSame(1, UserSession::where('user_id', $user->id)->count());
    }

    public function test_signing_out_removes_the_row_of_that_browser_only(): void
    {
        $user = User::factory()->create();
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $other = $this->otherBrowser($user);

        $this->browser()->post('/logout');

        $this->assertSame([$other->id], UserSession::pluck('id')->all());
    }

    public function test_changing_the_password_ends_the_other_sessions_and_keeps_this_one(): void
    {
        $user = User::factory()->create();
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $other = $this->otherBrowser($user);

        $this->browser()->put('/account/password', ['current_password' => 'password', 'password' => 'New-password-123!', 'password_confirmation' => 'New-password-123!']);

        $this->assertNull(UserSession::find($other->id));
        $this->assertSame('', Session::getHandler()->read(Crypt::decryptString($other->session_id_enc)));
        $rows = UserSession::where('user_id', $user->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame(UserSessions::hash(session()->getId()), $rows[0]->session_hash);
    }

    public function test_the_list_keeps_one_row_when_the_session_id_is_regenerated(): void
    {
        $user = User::factory()->create();
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $first = session()->getId();
        session()->regenerate();

        $this->browser()->get('/sites');

        $this->assertSame(1, UserSession::count());
        $this->assertNotSame($first, session()->getId());
        $this->assertSame(UserSessions::hash(session()->getId()), UserSession::firstOrFail()->session_hash);
    }

    public function test_rows_follow_their_account_when_it_is_deleted(): void
    {
        $user = User::factory()->create();
        $this->otherBrowser($user);

        $user->delete();

        $this->assertSame(0, UserSession::count());
    }

    public function test_a_session_id_that_cannot_be_decrypted_is_removed_from_the_list_without_an_error(): void
    {
        $user = User::factory()->create();
        $row = $this->otherBrowser($user);
        $row->forceFill(['session_id_enc' => 'broken'])->save();

        app(UserSessions::class)->revoke($row);

        $this->assertNull(UserSession::find($row->id));
    }
}
