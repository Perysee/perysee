<?php

namespace Tests\Feature;

use App\Models\Website;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PruneContinuityLogTest extends TestCase
{
    use RefreshDatabase;

    private function entry(?Website $site, string $kind = 'consent', string $severity = 'breaks', ?string $when = null): int
    {
        return DB::table('continuity_log')->insertGetId([
            'website_id' => $site?->id, 'occurred_at' => $when ?? now('UTC')->format('Y-m-d H:i:s.v'), 'kind' => $kind,
            'severity' => $severity, 'scope' => 'all', 'payload' => '{}',
        ]);
    }

    /** @return list<int> */
    private function ids(?Website $site = null): array
    {
        return DB::table('continuity_log')->when($site, fn ($query) => $query->where('website_id', $site->id))->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function test_entries_older_than_the_retention_are_removed_and_newer_ones_kept(): void
    {
        $site = Website::factory()->create();
        $this->entry($site, when: now('UTC')->subDays(1096)->format('Y-m-d H:i:s.v'));
        $this->entry(null, 'retention_change', when: now('UTC')->subDays(2000)->format('Y-m-d H:i:s.v'));
        $kept = $this->entry($site, when: now('UTC')->subDays(1094)->format('Y-m-d H:i:s.v'));

        $this->artisan('perysee:prune-continuity-log')->assertSuccessful();

        $this->assertSame([$kept], $this->ids());
    }

    public function test_a_backlog_is_cleared_over_several_batches_and_a_run_is_capped(): void
    {
        $site = Website::factory()->create();
        for ($index = 0; $index < 45; $index++) {
            $this->entry($site, when: now('UTC')->subDays(2000)->format('Y-m-d H:i:s.v'));
        }

        $this->artisan('perysee:prune-continuity-log', ['--limit' => 2])->assertSuccessful();
        $this->assertCount(5, $this->ids(), '20 batches of 2 per run.');

        $this->artisan('perysee:prune-continuity-log', ['--limit' => 2])->assertSuccessful();
        $this->assertSame([], $this->ids());
    }

    public function test_an_invalid_limit_is_refused(): void
    {
        $this->artisan('perysee:prune-continuity-log', ['--limit' => 0])->assertFailed();
        $this->artisan('perysee:prune-continuity-log', ['--limit' => 10001])->assertFailed();
    }

    public function test_a_site_over_the_cap_loses_its_oldest_notes_first(): void
    {
        $site = Website::factory()->create();
        $other = Website::factory()->create();
        $break = $this->entry($site, 'consent', 'breaks');
        $this->entry($site, 'timezone', 'note');
        for ($index = 0; $index < 499; $index++) {
            $this->entry($site, 'allowed_domains', 'note');
        }
        $this->entry($other, 'consent', 'breaks');
        $this->assertCount(501, $this->ids($site));

        $this->artisan('perysee:prune-continuity-log')->assertSuccessful();

        $remaining = $this->ids($site);
        $this->assertCount(500, $remaining);
        $this->assertContains($break, $remaining);
        $this->assertCount(1, $this->ids($other));
        $this->assertSame(1, DB::table('continuity_log')->where('website_id', $site->id)->where('kind', 'timezone')->count(), 'The only timezone entry is the last of its kind.');
        $this->assertSame(498, DB::table('continuity_log')->where('website_id', $site->id)->where('kind', 'allowed_domains')->count());
    }

    public function test_the_cap_removes_breaks_only_once_the_notes_are_gone_and_never_the_last_of_a_kind(): void
    {
        $site = Website::factory()->create();
        $oldest = $this->entry($site, 'consent', 'breaks');
        $this->entry($site, 'currency', 'breaks');
        for ($index = 0; $index < 505; $index++) {
            $this->entry($site, 'excluded_paths', 'breaks');
        }
        $note = $this->entry($site, 'timezone', 'note');
        $lastExcluded = (int) DB::table('continuity_log')->where('kind', 'excluded_paths')->max('id');
        $this->assertCount(508, $this->ids($site));

        $this->artisan('perysee:prune-continuity-log')->assertSuccessful();

        $remaining = $this->ids($site);
        $this->assertCount(500, $remaining);
        $this->assertContains($note, $remaining, 'The last entry of a kind survives, even a note.');
        $this->assertContains($oldest, $remaining, 'The last entry of a kind survives.');
        $this->assertContains($lastExcluded, $remaining);
        $this->assertSame(1, DB::table('continuity_log')->where('kind', 'currency')->count());
        $this->assertSame(497, DB::table('continuity_log')->where('kind', 'excluded_paths')->count());
    }

    public function test_the_instance_entries_have_their_own_cap(): void
    {
        for ($index = 0; $index < 503; $index++) {
            $this->entry(null, 'retention_change', 'note');
        }

        $this->artisan('perysee:prune-continuity-log')->assertSuccessful();

        $this->assertCount(500, $this->ids());
    }

    public function test_an_empty_table_costs_one_read(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $this->artisan('perysee:prune-continuity-log')->assertSuccessful();

        $this->assertCount(1, $queries, implode(' | ', $queries));
    }

    public function test_the_purge_is_scheduled_hourly_without_overlap(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command, 'perysee:prune-continuity-log'));

        $this->assertNotNull($event);
        $this->assertSame('0 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }
}
