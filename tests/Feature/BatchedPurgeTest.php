<?php

namespace Tests\Feature;

use App\Analytics\Maintenance\SiteTables;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BatchedPurgeTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<string> */
    private function deletes(callable $work): array
    {
        $deletes = [];
        DB::listen(function ($query) use (&$deletes): void {
            if (str_starts_with($query->sql, 'delete')) {
                $deletes[] = $query->sql;
            }
        });
        $work();

        return $deletes;
    }

    private function fill(Website $site, int $rows, string $day = '2026-10-01'): void
    {
        for ($i = 0; $i < $rows; $i++) {
            $date = date('Y-m-d', strtotime($day.' +'.$i.' days'));
            DB::table('events')->insert(['website_id' => $site->id, 'path' => '/', 'received_at' => $date.' 10:00:00', 'consent_given' => true, 'processed_at' => now()]);
            DB::table('stats_daily')->insert(['website_id' => $site->id, 'stat_date' => $date]);
            DB::table('stats_dimension_daily')->insert(['website_id' => $site->id, 'stat_date' => $date, 'dimension_type' => 'path', 'dimension_hash' => str_repeat('a', 64), 'dimension_value' => '/', 'metric_count' => 1]);
            DB::table('measured_sessions')->insert(['website_id' => $site->id, 'session_key' => 'session-'.$i, 'first_seen_at' => $date.' 10:00:00', 'last_seen_at' => $date.' 10:00:00']);
        }
    }

    public function test_deleting_a_site_removes_its_rows_in_batches_and_leaves_other_sites_alone(): void
    {
        $site = Website::factory()->create(['deleting_at' => now()]);
        $other = Website::factory()->create();
        $this->fill($site, 25);
        $this->fill($other, 25);

        $deletes = $this->deletes(fn () => $this->artisan('perysee:maintenance', ['--limit' => 1000])->assertSuccessful());

        foreach (['events', 'stats_daily', 'stats_dimension_daily', 'measured_sessions'] as $table) {
            $this->assertSame(0, DB::table($table)->where('website_id', $site->id)->count(), $table);
            $this->assertSame(25, DB::table($table)->where('website_id', $other->id)->count(), $table);
        }
        $this->assertDatabaseMissing('websites', ['id' => $site->id]);
        $this->assertLessThanOrEqual(12, count($deletes), implode(' | ', $deletes));
    }

    public function test_a_site_purge_stops_at_the_limit_and_resumes_on_the_next_pass(): void
    {
        $site = Website::factory()->create(['deleting_at' => now()]);
        $this->fill($site, 25);

        $deletes = $this->deletes(fn () => $this->artisan('perysee:maintenance', ['--limit' => 10])->assertSuccessful());

        $this->assertSame(15, DB::table('events')->where('website_id', $site->id)->count());
        $this->assertSame(25, DB::table('stats_daily')->where('website_id', $site->id)->count());
        $this->assertDatabaseHas('websites', ['id' => $site->id]);
        $this->assertCount(1, $deletes, implode(' | ', $deletes));
        for ($pass = 0; $pass < 10 && Website::whereKey($site->id)->exists(); $pass++) {
            $this->artisan('perysee:maintenance', ['--limit' => 10])->assertSuccessful();
        }
        $this->assertDatabaseMissing('websites', ['id' => $site->id]);
        $this->assertSame(0, DB::table('stats_dimension_daily')->count());
    }

    public function test_expired_statistics_are_deleted_in_batches(): void
    {
        $first = Website::factory()->create();
        $second = Website::factory()->create();
        $this->fill($first, 25, '2020-01-01');
        $this->fill($second, 25, '2020-01-01');
        DB::table('events')->delete();

        $deletes = $this->deletes(fn () => $this->artisan('perysee:maintenance', ['--limit' => 1000])->assertSuccessful());

        $this->assertSame(0, DB::table('stats_daily')->count());
        $this->assertSame(0, DB::table('stats_dimension_daily')->count());
        $this->assertLessThanOrEqual(6, count($deletes), implode(' | ', $deletes));
    }

    public function test_expired_journey_reports_go_with_their_sessions_in_batches(): void
    {
        $site = Website::factory()->create();
        foreach (range(1, 5) as $report) {
            $id = DB::table('journey_reports')->insertGetId(['website_id' => $site->id, 'cache_key' => str_pad((string) $report, 64, '0'), 'snapshot_id' => 1, 'payload' => '{}', 'created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2)]);
            foreach (range(1, 10) as $session) {
                DB::table('journey_report_sessions')->insert(['website_id' => $site->id, 'report_id' => $id, 'session_key' => 'session-'.$session, 'events' => '[]']);
            }
        }
        $fresh = DB::table('journey_reports')->insertGetId(['website_id' => $site->id, 'cache_key' => str_repeat('f', 64), 'snapshot_id' => 1, 'payload' => '{}', 'created_at' => now(), 'updated_at' => now()]);

        $deletes = $this->deletes(fn () => $this->artisan('perysee:maintenance', ['--limit' => 20])->assertSuccessful());

        $this->assertLessThanOrEqual(6, count($deletes), implode(' | ', $deletes));
        $this->assertSame(30, DB::table('journey_report_sessions')->count());
        for ($pass = 0; $pass < 5; $pass++) {
            $this->artisan('perysee:maintenance', ['--limit' => 20])->assertSuccessful();
        }
        $this->assertSame(0, DB::table('journey_report_sessions')->count());
        $this->assertSame([$fresh], DB::table('journey_reports')->pluck('id')->all());
    }

    public function test_expired_goal_sessions_are_deleted_in_batches(): void
    {
        $site = Website::factory()->create();
        foreach (range(1, 30) as $session) {
            DB::table('goal_sessions')->insert(['website_id' => $site->id, 'goal_id' => 1, 'version' => 1, 'session_key' => 'session-'.$session, 'hits' => 1, 'last_hit_at' => now()->subDays(200)]);
        }
        DB::table('goal_sessions')->insert(['website_id' => $site->id, 'goal_id' => 1, 'version' => 1, 'session_key' => 'recent', 'hits' => 1, 'last_hit_at' => now()]);

        $deletes = $this->deletes(fn () => $this->artisan('perysee:prune-tracking', ['--limit' => 1000])->assertSuccessful());

        $this->assertSame(['recent'], DB::table('goal_sessions')->pluck('session_key')->all());
        $this->assertCount(3, $deletes, implode(' | ', $deletes));
    }

    public function test_the_site_tables_cover_every_table_that_belongs_to_a_site(): void
    {
        $uncovered = [];
        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            if (! Schema::hasColumn($table, 'website_id') || array_key_exists($table, SiteTables::KEYS)) {
                continue;
            }
            // A table that the database removes with the site (foreign key on delete cascade) needs no purge.
            $cascades = collect(Schema::getForeignKeys($table))->contains(fn (array $key): bool => $key['foreign_table'] === 'websites' && $key['columns'] === ['website_id'] && $key['on_delete'] === 'cascade');
            if (! $cascades) {
                $uncovered[] = $table;
            }
        }

        $this->assertSame([], $uncovered);
    }

    public function test_the_site_tables_name_real_columns_and_the_demo_list_is_one_of_them(): void
    {
        foreach (SiteTables::KEYS as $table => $keys) {
            foreach ([...$keys, 'website_id'] as $column) {
                $this->assertTrue(Schema::hasColumn($table, $column), $table.'.'.$column);
            }
        }
        $this->assertSame([], array_values(array_diff(SiteTables::DEMO, array_keys(SiteTables::KEYS))));
    }
}
