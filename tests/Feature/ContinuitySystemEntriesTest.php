<?php

namespace Tests\Feature;

use App\Analytics\Aggregation\Aggregator;
use App\Analytics\Aggregation\Goals;
use App\Analytics\Aggregation\Segments;
use App\Analytics\InstanceHealth;
use App\Installation\InstallationState;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The entries the system writes by itself: updates, rebuilds and the automatic retention purges. */
class ContinuitySystemEntriesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
    }

    /** @return list<object> */
    private function entries(?string $kind = null): array
    {
        return DB::table('continuity_log')->when($kind, fn ($query) => $query->where('kind', $kind))->orderBy('id')->get()->all();
    }

    public function test_an_update_to_another_version_leaves_one_note_and_remembers_the_version(): void
    {
        config(['perysee.version' => '0.4.0']);
        app(InstanceHealth::class)->put('last_seen_version', '0.3.2');

        $this->artisan('perysee:update:finalize --expect=0.4.0')->assertSuccessful();
        $this->artisan('perysee:update:finalize --expect=0.4.0')->assertSuccessful();

        $rows = $this->entries('core_version');
        $this->assertCount(1, $rows, 'finalizing again on the same version writes nothing');
        $this->assertNull($rows[0]->website_id);
        $this->assertSame('note', $rows[0]->severity);
        $this->assertSame('all', $rows[0]->scope);
        $this->assertSame(now('UTC')->toDateString(), $rows[0]->data_from);
        $this->assertSame(['from' => '0.3.2', 'to' => '0.4.0'], json_decode($rows[0]->payload, true));
        $this->assertSame('0.4.0', app(InstanceHealth::class)->get('last_seen_version'));
    }

    public function test_the_first_finalize_only_remembers_the_version(): void
    {
        config(['perysee.version' => '0.4.0']);

        $this->artisan('perysee:update:finalize --expect=0.4.0')->assertSuccessful();

        $this->assertSame([], $this->entries());
        $this->assertSame('0.4.0', app(InstanceHealth::class)->get('last_seen_version'));
        $this->assertSame(config('perysee.tracker_version'), app(InstanceHealth::class)->get('last_seen_tracker_version'));
    }

    public function test_a_new_tracker_version_is_logged_once_when_it_changes(): void
    {
        config(['perysee.version' => '0.4.0', 'perysee.tracker_version' => '1.2.0']);
        app(InstanceHealth::class)->put('last_seen_version', '0.4.0');
        app(InstanceHealth::class)->put('last_seen_tracker_version', '1.1.0');

        $this->artisan('perysee:update:finalize --expect=0.4.0')->assertSuccessful();
        $this->artisan('perysee:update:finalize --expect=0.4.0')->assertSuccessful();

        $this->assertSame([], $this->entries('core_version'));
        $rows = $this->entries('tracker_version');
        $this->assertCount(1, $rows);
        $this->assertSame('note', $rows[0]->severity);
        $this->assertSame(['from' => '1.1.0', 'to' => '1.2.0'], json_decode($rows[0]->payload, true));
    }

    public function test_the_tracker_version_constant_follows_the_file_name(): void
    {
        $this->assertMatchesRegularExpression('/^1\.\d+\.\d+$/', config('perysee.tracker_version'), 'the tracker file is lueur.v1.js: its major version is 1');
    }

    public function test_finishing_the_segment_rebuild_writes_one_entry_for_the_instance(): void
    {
        $site = Website::factory()->create();
        DB::table('events')->insert(['website_id' => $site->id, 'received_at' => now('UTC')->subHour(), 'processed_at' => now('UTC'), 'event_type' => 1, 'path' => '/', 'consent_given' => true]);

        $this->assertSame(1, app(Segments::class)->rebuild(500));

        $rows = $this->entries('rebuild');
        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]->website_id);
        $this->assertSame('segments', $rows[0]->scope);
        $this->assertSame('note', $rows[0]->severity);
        $this->assertSame(DB::table('events')->max('id'), json_decode($rows[0]->payload, true)['through']);
        $this->assertSame(0, app(Segments::class)->rebuild(500), 'nothing left to rebuild');
        $this->assertCount(1, $this->entries('rebuild'));
    }

    public function test_a_segment_rebuild_that_is_not_finished_writes_nothing_yet(): void
    {
        $site = Website::factory()->create();
        foreach ([1, 2, 3] as $minutes) {
            DB::table('events')->insert(['website_id' => $site->id, 'received_at' => now('UTC')->subHour(), 'processed_at' => now('UTC'), 'event_type' => 1, 'path' => '/'.$minutes, 'consent_given' => true]);
        }

        app(Segments::class)->rebuild(2);
        $this->assertSame([], $this->entries('rebuild'));

        app(Segments::class)->rebuild(2);
        $this->assertCount(1, $this->entries('rebuild'));
    }

    public function test_finishing_the_goal_backfill_writes_one_entry_for_the_site(): void
    {
        $site = Website::factory()->create();
        $goal = DB::table('goals')->insertGetId(['website_id' => $site->id, 'name' => 'Signup', 'current_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('goal_versions')->insert(['website_id' => $site->id, 'goal_id' => $goal, 'version' => 1, 'type' => 'path', 'value' => '/thanks', 'threshold' => 1, 'snapshot_id' => 10, 'created_at' => now()]);
        DB::table('analytics_state')->insert(['name' => 'goals_backfill:'.$goal.':1', 'value' => json_encode(['site' => $site->id, 'cursor' => 0, 'snapshot' => 10]), 'updated_at' => now()]);

        app(Goals::class)->rebuild(500);

        $rows = $this->entries('rebuild');
        $this->assertCount(1, $rows);
        $this->assertSame($site->id, (int) $rows[0]->website_id);
        $this->assertSame('goals', $rows[0]->scope);
        $this->assertSame('note', $rows[0]->severity);
        $this->assertSame(['target' => 'goals', 'goal_id' => $goal, 'version' => 1, 'through' => 10], json_decode($rows[0]->payload, true));
        app(Goals::class)->rebuild(500);
        $this->assertCount(1, $this->entries('rebuild'));
    }

    private function expiredEvent(int $daysAgo): void
    {
        $site = Website::query()->first() ?? Website::factory()->create();
        DB::table('events')->insert(['website_id' => $site->id, 'received_at' => now('UTC')->subDays($daysAgo), 'processed_at' => now('UTC')->subDays($daysAgo), 'event_type' => 1, 'path' => '/', 'consent_given' => true]);
    }

    public function test_the_automatic_event_purge_writes_one_note_a_day(): void
    {
        $this->expiredEvent(400);
        $this->artisan('perysee:prune-tracking')->assertSuccessful();
        $this->expiredEvent(401);
        $this->artisan('perysee:prune-tracking')->assertSuccessful();

        $rows = $this->entries('retention_purge');
        $this->assertCount(1, $rows, 'a second purge the same day writes nothing');
        $this->assertNull($rows[0]->website_id);
        $this->assertSame('note', $rows[0]->severity);
        $this->assertSame(now('UTC')->subDays(app(InstanceHealth::class)->effectiveRetentionDays('raw_retention_days'))->toDateString(), $rows[0]->data_from);
        $this->assertSame(['target' => 'events'], json_decode($rows[0]->payload, true));

        $this->travel(1)->day();
        $this->expiredEvent(400);
        $this->artisan('perysee:prune-tracking')->assertSuccessful();
        $this->assertCount(2, $this->entries('retention_purge'), 'another day, another cutoff date');
    }

    public function test_a_purge_that_deletes_nothing_writes_nothing(): void
    {
        $this->expiredEvent(1);

        $this->artisan('perysee:prune-tracking')->assertSuccessful();
        $this->artisan('perysee:prune-sessions')->assertSuccessful();
        $this->artisan('perysee:maintenance')->assertSuccessful();

        $this->assertSame([], $this->entries());
    }

    public function test_the_other_automatic_purges_write_their_own_genre(): void
    {
        $site = Website::factory()->create(['timezone' => 'UTC']);
        $old = now('UTC')->subDays(2000)->toDateString();
        DB::table('stats_daily')->insert(['website_id' => $site->id, 'stat_date' => $old, 'pageviews' => 1]);
        DB::table('stats_segment_daily')->insert(['website_id' => $site->id, 'stat_date' => $old, 'bucket_start' => $old.' 00:00:00', 'segment_hash' => str_repeat('a', 64), 'dimensions' => '{}', 'pageviews' => 1]);
        DB::table('measured_sessions')->insert(['website_id' => $site->id, 'session_key' => str_repeat('b', 64), 'first_seen_at' => $old.' 10:00:00', 'last_seen_at' => $old.' 10:05:00']);

        $this->artisan('perysee:maintenance')->assertSuccessful();
        $this->artisan('perysee:prune-sessions')->assertSuccessful();

        $rows = collect($this->entries('retention_purge'));
        $this->assertEqualsCanonicalizing(['stats', 'segments', 'sessions'], $rows->map(fn ($row) => json_decode($row->payload, true)['target'])->all());
        $this->assertSame(['all' => 'stats', 'segments' => 'segments', 'sessions' => 'sessions'], $rows->mapWithKeys(fn ($row) => [$row->scope => json_decode($row->payload, true)['target']])->all());
    }

    public function test_the_manual_purge_keeps_its_own_entry_and_the_automatic_one_does_not_double_it(): void
    {
        $this->expiredEvent(400);
        $this->artisan('perysee:purge', ['target' => 'events', '--older-than' => '30d', '--force' => true, '--yes' => true])->assertSuccessful();

        $rows = $this->entries('retention_purge');
        $this->assertCount(1, $rows);
        $this->assertSame('breaks', $rows[0]->severity);
    }

    public function test_the_automatic_purge_costs_nothing_more_when_idle(): void
    {
        $this->artisan('perysee:maintenance')->assertSuccessful();
        $queries = 0;
        DB::listen(function ($query) use (&$queries): void {
            $queries += str_contains($query->sql, 'continuity_log') ? 1 : 0;
        });

        $this->artisan('perysee:maintenance')->assertSuccessful();
        $this->artisan('perysee:prune-sessions')->assertSuccessful();
        $this->artisan('perysee:prune-tracking')->assertSuccessful();

        $this->assertSame(0, $queries);
    }

    public function test_the_aggregator_is_not_a_writer_of_the_log(): void
    {
        $this->expiredEvent(1);
        app(Aggregator::class)->run();

        $this->assertSame([], $this->entries());
    }
}
