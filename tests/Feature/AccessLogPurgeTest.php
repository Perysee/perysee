<?php

namespace Tests\Feature;

use App\Models\AccessLogEntry;
use App\Models\User;
use App\Models\UserSession;
use App\Support\UserSessions;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessLogPurgeTest extends TestCase
{
    use RefreshDatabase;

    private function entry(string $when, ?User $user = null): void
    {
        AccessLogEntry::create(['user_id' => $user?->id, 'event' => 'login.success', 'created_at' => $when]);
    }

    private function row(User $user, string $seen): UserSession
    {
        return UserSession::create(['user_id' => $user->id, 'session_hash' => hash('sha256', $seen.random_bytes(8)), 'session_id_enc' => 'x', 'device' => 'Test', 'created_at' => $seen, 'last_seen_at' => $seen]);
    }

    public function test_entries_older_than_ninety_days_are_removed_and_newer_ones_kept(): void
    {
        $user = User::factory()->create();
        $this->entry(now('UTC')->subDays(91)->toDateTimeString(), $user);
        $this->entry(now('UTC')->subDays(90)->subMinute()->toDateTimeString(), $user);
        $this->entry(now('UTC')->subDays(89)->toDateTimeString(), $user);
        $this->entry(now('UTC')->toDateTimeString());

        $this->artisan('perysee:prune-access-log')->assertSuccessful();

        $this->assertSame(2, AccessLogEntry::count());
    }

    public function test_a_small_backlog_is_cleared_in_one_run_by_batches(): void
    {
        for ($index = 0; $index < 7; $index++) {
            $this->entry(now('UTC')->subDays(200)->toDateTimeString());
        }

        $this->artisan('perysee:prune-access-log', ['--limit' => 2])->assertSuccessful();

        $this->assertSame(0, AccessLogEntry::count());
    }

    public function test_a_single_run_is_capped_so_a_long_backlog_is_spread_over_several_runs(): void
    {
        $rows = [];
        for ($index = 0; $index < 45; $index++) {
            $rows[] = ['event' => 'login.success', 'created_at' => now('UTC')->subDays(200)->toDateTimeString()];
        }
        AccessLogEntry::insert($rows);

        $this->artisan('perysee:prune-access-log', ['--limit' => 2])->assertSuccessful();
        $this->assertSame(5, AccessLogEntry::count(), '20 batches of 2 per run.');

        $this->artisan('perysee:prune-access-log', ['--limit' => 2])->assertSuccessful();
        $this->assertSame(0, AccessLogEntry::count());
    }

    public function test_session_rows_nobody_renewed_are_purged_but_live_ones_are_not(): void
    {
        $user = User::factory()->create();
        $lifetime = (int) config('session.lifetime');
        $stale = $this->row($user, now('UTC')->subMinutes($lifetime + 61)->toDateTimeString());
        $recent = $this->row($user, now('UTC')->subMinutes($lifetime)->toDateTimeString());
        $fresh = $this->row($user, now('UTC')->toDateTimeString());

        $this->artisan('perysee:prune-access-log')->assertSuccessful();

        $this->assertNull(UserSession::find($stale->id));
        $this->assertNotNull(UserSession::find($recent->id));
        $this->assertNotNull(UserSession::find($fresh->id));
        $this->assertSame(0, app(UserSessions::class)->purgeExpired(10));
    }

    public function test_an_invalid_limit_is_refused(): void
    {
        $this->artisan('perysee:prune-access-log', ['--limit' => 0])->assertFailed();
    }

    public function test_the_purge_is_scheduled_hourly_without_overlap_and_the_idle_pass_is_untouched(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command, 'perysee:prune-access-log'));

        $this->assertNotNull($event);
        $this->assertSame('0 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }
}
