<?php

namespace Tests\Feature;

use App\Analytics\InstanceHealth;
use App\Models\Website;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SessionPurgeTest extends TestCase
{
    use RefreshDatabase;

    private function registry(Website $site, string $key, int $daysAgo): void
    {
        $seen = now('UTC')->subDays($daysAgo)->format('Y-m-d H:i:s.v');
        DB::table('measured_sessions')->insert(['website_id' => $site->id, 'session_key' => $key, 'first_seen_at' => $seen, 'last_seen_at' => $seen]);
        DB::table('segment_sessions')->insert(['website_id' => $site->id, 'session_key' => $key, 'first_event_id' => 1, 'first_seen_at' => $seen, 'event_data' => '{}']);
    }

    private function event(Website $site, int $daysAgo, bool $processed): void
    {
        DB::table('events')->insert(['website_id' => $site->id, 'received_at' => now('UTC')->subDays($daysAgo), 'event_type' => 1, 'path' => '/', 'processed_at' => $processed ? now() : null, 'consent_given' => true]);
    }

    /** @return list<string> */
    private function keys(string $table): array
    {
        return DB::table($table)->orderBy('session_key')->pluck('session_key')->all();
    }

    public function test_sessions_older_than_the_statistics_retention_are_deleted_and_recent_ones_kept(): void
    {
        $site = Website::factory()->create();
        $this->registry($site, 'old', 1200);
        $this->registry($site, 'beyond-margin', 1097);
        $this->registry($site, 'inside-margin', 1095);
        $this->registry($site, 'recent', 3);

        $this->artisan('perysee:prune-sessions')->assertSuccessful();

        $this->assertSame(['inside-margin', 'recent'], $this->keys('measured_sessions'));
        $this->assertSame(['inside-margin', 'recent'], $this->keys('segment_sessions'));
    }

    public function test_the_cutoff_follows_the_configured_statistics_retention(): void
    {
        $site = Website::factory()->create();
        app(InstanceHealth::class)->put('stats_retention_days', 30);
        $this->registry($site, 'old', 40);
        $this->registry($site, 'recent', 10);

        $this->artisan('perysee:prune-sessions')->assertSuccessful();

        $this->assertSame(['recent'], $this->keys('measured_sessions'));
        $this->assertSame(['recent'], $this->keys('segment_sessions'));
    }

    public function test_nothing_is_deleted_while_an_older_unprocessed_event_is_waiting(): void
    {
        $site = Website::factory()->create();
        $this->registry($site, 'old', 1200);
        $this->event($site, 1200, processed: false);

        $this->artisan('perysee:prune-sessions')->assertSuccessful();

        $this->assertSame(['old'], $this->keys('measured_sessions'));
        $this->assertSame(['old'], $this->keys('segment_sessions'));

        DB::table('events')->update(['processed_at' => now()]);
        $this->artisan('perysee:prune-sessions')->assertSuccessful();

        $this->assertSame([], $this->keys('measured_sessions'));
        $this->assertSame([], $this->keys('segment_sessions'));
    }

    public function test_a_recent_unprocessed_event_does_not_hold_the_purge_back(): void
    {
        $site = Website::factory()->create();
        $this->registry($site, 'old', 1200);
        $this->event($site, 1, processed: false);

        $this->artisan('perysee:prune-sessions')->assertSuccessful();

        $this->assertSame([], $this->keys('measured_sessions'));
    }

    public function test_a_backlog_is_cleared_over_several_batches_and_a_run_is_capped(): void
    {
        $site = Website::factory()->create();
        foreach (range(1, 45) as $index) {
            $this->registry($site, 'old-'.$index, 1200);
        }

        $this->artisan('perysee:prune-sessions', ['--limit' => 2])->assertSuccessful();
        $this->assertSame(5, DB::table('measured_sessions')->count(), '20 batches of 2 per run.');
        $this->assertSame(5, DB::table('segment_sessions')->count());

        $this->artisan('perysee:prune-sessions', ['--limit' => 2])->assertSuccessful();
        $this->assertSame(0, DB::table('measured_sessions')->count());
        $this->assertSame(0, DB::table('segment_sessions')->count());
    }

    public function test_an_invalid_limit_is_refused(): void
    {
        $this->artisan('perysee:prune-sessions', ['--limit' => 0])->assertFailed();
        $this->artisan('perysee:prune-sessions', ['--limit' => 10001])->assertFailed();
    }

    public function test_an_idle_pass_costs_two_reads_and_no_write(): void
    {
        $site = Website::factory()->create();
        $this->registry($site, 'recent', 3);
        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $this->artisan('perysee:prune-sessions')->assertSuccessful();

        $this->assertCount(2, $statements, implode(' | ', $statements));
        $this->assertSame([], array_filter($statements, fn (string $sql): bool => ! str_starts_with($sql, 'select')));
    }

    public function test_it_is_a_dedicated_hourly_command_that_does_not_overlap(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command, 'perysee:prune-sessions'));
        $this->assertNotNull($event);
        $this->assertSame('0 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $maintenance = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command, 'perysee:maintenance'));
        $this->assertStringNotContainsString('sessions', $maintenance->command);
    }

    public function test_the_first_seen_index_exists_and_the_migration_can_run_again(): void
    {
        $this->assertTrue(\Schema::hasIndex('segment_sessions', 'segment_sessions_first_seen_at_index'));
        $migration = require base_path('database/migrations/2026_11_09_120000_index_segment_sessions_first_seen.php');
        $migration->up();
        $migration->up();
        $this->assertTrue(\Schema::hasIndex('segment_sessions', 'segment_sessions_first_seen_at_index'));
    }
}
