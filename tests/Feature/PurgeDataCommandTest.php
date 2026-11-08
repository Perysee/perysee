<?php

namespace Tests\Feature;

use App\Analytics\InstanceHealth;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PurgeDataCommandTest extends TestCase
{
    use RefreshDatabase;

    private Website $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Website::factory()->create();
    }

    private function event(int $daysAgo, bool $processed = true): int
    {
        return DB::table('events')->insertGetId(['website_id' => $this->site->id, 'received_at' => now('UTC')->subDays($daysAgo), 'event_type' => 1, 'path' => '/', 'processed_at' => $processed ? now() : null, 'consent_given' => true]);
    }

    private function stat(int $daysAgo): void
    {
        $date = now('UTC')->subDays($daysAgo)->toDateString();
        DB::table('stats_daily')->insert(['website_id' => $this->site->id, 'stat_date' => $date, 'pageviews' => 1]);
    }

    private function registry(string $key, int $daysAgo): void
    {
        $seen = now('UTC')->subDays($daysAgo)->format('Y-m-d H:i:s.v');
        DB::table('measured_sessions')->insert(['website_id' => $this->site->id, 'session_key' => $key, 'first_seen_at' => $seen, 'last_seen_at' => $seen]);
        DB::table('segment_sessions')->insert(['website_id' => $this->site->id, 'session_key' => $key, 'first_event_id' => 1, 'first_seen_at' => $seen, 'event_data' => '{}']);
    }

    public function test_without_force_it_only_counts_and_deletes_nothing(): void
    {
        $this->event(40);
        $this->event(50);
        $this->event(1);
        $this->event(45, processed: false);

        $this->artisan('perysee:purge', ['target' => 'events', '--older-than' => '30d'])
            ->expectsOutputToContain('Simulation of "events"')
            ->expectsOutputToContain('Total: 2 row(s)')
            ->expectsOutputToContain('1 event(s) older than the cutoff are not consolidated yet')
            ->expectsOutputToContain('Take a backup')
            ->expectsOutputToContain('nothing was deleted')
            ->assertSuccessful();

        $this->assertSame(4, DB::table('events')->count());
        $this->assertSame(0, DB::table('continuity_log')->count());
    }

    public function test_dry_run_wins_over_force(): void
    {
        $this->event(40);

        $this->artisan('perysee:purge', ['target' => 'events', '--older-than' => '30d', '--force' => true, '--yes' => true, '--dry-run' => true])->assertSuccessful();

        $this->assertSame(1, DB::table('events')->count());
    }

    public function test_force_with_yes_deletes_the_processed_events_in_bounded_batches(): void
    {
        foreach (range(1, 7) as $index) {
            $this->event(40 + $index);
        }
        $recent = $this->event(2);
        $waiting = $this->event(60, processed: false);

        $this->artisan('perysee:purge', ['target' => 'events', '--older-than' => '30d', '--force' => true, '--yes' => true, '--limit' => 3])
            ->expectsOutputToContain('Deleted 7 rows')
            ->assertSuccessful();

        $this->assertEqualsCanonicalizing([$recent, $waiting], DB::table('events')->pluck('id')->all(), 'An unprocessed event is never deleted.');
    }

    public function test_force_asks_for_a_confirmation_and_cancelling_deletes_nothing(): void
    {
        $this->event(40);

        $this->artisan('perysee:purge', ['target' => 'events', '--older-than' => '30d', '--force' => true])
            ->expectsConfirmation('Delete these rows permanently?', 'no')
            ->expectsOutputToContain('Cancelled')
            ->assertFailed();

        $this->assertSame(1, DB::table('events')->count());

        $this->artisan('perysee:purge', ['target' => 'events', '--older-than' => '30d', '--force' => true])
            ->expectsConfirmation('Delete these rows permanently?', 'yes')
            ->assertSuccessful();

        $this->assertSame(0, DB::table('events')->count());
    }

    public function test_without_a_terminal_a_forced_purge_needs_yes(): void
    {
        $this->event(40);

        $this->artisan('perysee:purge', ['target' => 'events', '--older-than' => '30d', '--force' => true, '--no-interaction' => true])->expectsOutputToContain('needs a terminal')->assertFailed();

        $this->assertSame(1, DB::table('events')->count());
    }

    public function test_yes_without_force_is_refused(): void
    {
        $this->event(40);

        $this->artisan('perysee:purge', ['target' => 'events', '--older-than' => '30d', '--yes' => true])->assertFailed();

        $this->assertSame(1, DB::table('events')->count());
    }

    public function test_a_purge_shorter_than_the_retention_writes_a_break_in_the_continuity_log(): void
    {
        $this->event(40);

        $this->artisan('perysee:purge', ['target' => 'events', '--older-than' => '30d', '--force' => true, '--yes' => true])->assertSuccessful();

        $entry = DB::table('continuity_log')->where('kind', 'retention_purge')->first();
        $this->assertNotNull($entry);
        $this->assertNull($entry->website_id);
        $this->assertSame('breaks', $entry->severity);
        $this->assertSame(now('UTC')->subDays(30)->toDateString(), substr((string) $entry->data_from, 0, 10));
        $payload = json_decode($entry->payload, true);
        $this->assertSame(['target' => 'events', 'older_than_days' => 30, 'retention_days' => 90, 'rows' => 1], $payload);
    }

    public function test_a_purge_not_shorter_than_the_retention_writes_nothing(): void
    {
        $this->event(120);

        $this->artisan('perysee:purge', ['target' => 'events', '--older-than' => '3m', '--force' => true, '--yes' => true])->assertSuccessful();

        $this->assertSame(0, DB::table('events')->count());
        $this->assertSame(0, DB::table('continuity_log')->count());
    }

    public function test_nothing_deleted_leaves_the_continuity_log_alone(): void
    {
        $this->event(2);

        $this->artisan('perysee:purge', ['target' => 'events', '--older-than' => '30d', '--force' => true, '--yes' => true])->expectsOutputToContain('Nothing to delete')->assertSuccessful();

        $this->assertSame(0, DB::table('continuity_log')->count());
    }

    public function test_the_statistics_are_kept_while_an_older_unprocessed_event_is_waiting(): void
    {
        $this->stat(100);
        $this->event(100, processed: false);

        $this->artisan('perysee:purge', ['target' => 'stats', '--older-than' => '30d'])->expectsOutputToContain('kept until it is')->assertSuccessful();
        $this->artisan('perysee:purge', ['target' => 'stats', '--older-than' => '30d', '--force' => true, '--yes' => true])->assertFailed();

        $this->assertSame(1, DB::table('stats_daily')->count());
        $this->assertSame(0, DB::table('continuity_log')->count());

        DB::table('events')->update(['processed_at' => now()]);
        $this->artisan('perysee:purge', ['target' => 'stats', '--older-than' => '30d', '--force' => true, '--yes' => true])->assertSuccessful();

        $this->assertSame(0, DB::table('stats_daily')->count());
        $this->assertSame(1, DB::table('continuity_log')->where('kind', 'retention_purge')->count());
    }

    public function test_sessions_follow_the_same_guard_and_scope(): void
    {
        $this->registry('old', 100);
        $this->registry('recent', 5);

        $this->artisan('perysee:purge', ['target' => 'sessions', '--older-than' => '30d', '--force' => true, '--yes' => true])->assertSuccessful();

        $this->assertSame(['recent'], DB::table('measured_sessions')->pluck('session_key')->all());
        $this->assertSame(['recent'], DB::table('segment_sessions')->pluck('session_key')->all());
        $this->assertSame('sessions', DB::table('continuity_log')->value('scope'));
    }

    public function test_share_links_ended_before_the_cutoff_are_purged_without_a_break(): void
    {
        DB::table('share_links')->insert([
            ['website_id' => $this->site->id, 'token_hash' => str_repeat('a', 64), 'token_hint' => 'aaaaaa', 'sections' => '[]', 'revoked_at' => now()->subDays(20), 'created_at' => now(), 'updated_at' => now()],
            ['website_id' => $this->site->id, 'token_hash' => str_repeat('b', 64), 'token_hint' => 'bbbbbb', 'sections' => '[]', 'revoked_at' => now()->subDays(2), 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->artisan('perysee:purge', ['target' => 'share-links', '--older-than' => '7d', '--force' => true, '--yes' => true])->assertSuccessful();

        $this->assertSame([str_repeat('b', 64)], DB::table('share_links')->pluck('token_hash')->all());
        $this->assertSame(0, DB::table('continuity_log')->count());
    }

    public function test_an_unknown_target_a_missing_or_too_short_age_and_a_bad_limit_are_refused(): void
    {
        $this->event(40);

        $this->artisan('perysee:purge', ['target' => 'websites', '--older-than' => '30d', '--force' => true, '--yes' => true])->assertFailed();
        $this->artisan('perysee:purge', ['target' => 'events', '--force' => true, '--yes' => true])->assertFailed();
        foreach (['0d', '12h', '30', 'abc', '-5d', '1000000d'] as $age) {
            $this->artisan('perysee:purge', ['target' => 'events', '--older-than' => $age, '--force' => true, '--yes' => true])->assertFailed();
        }
        $this->artisan('perysee:purge', ['target' => 'events', '--older-than' => '30d', '--limit' => 0])->assertFailed();

        $this->assertSame(1, DB::table('events')->count());
    }

    public function test_it_follows_the_retention_settings_to_decide_whether_it_is_a_break(): void
    {
        app(InstanceHealth::class)->put('raw_retention_days', 20);
        $this->event(25);

        $this->artisan('perysee:purge', ['target' => 'events', '--older-than' => '21d', '--force' => true, '--yes' => true])->assertSuccessful();

        $this->assertSame(0, DB::table('continuity_log')->count(), 'Older than the retention in force: the hourly purge would have removed it.');
    }
}
