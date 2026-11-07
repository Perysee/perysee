<?php

namespace Tests\Feature;

use App\Models\Website;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CollectionDiagnosticsPurgeTest extends TestCase
{
    use RefreshDatabase;

    private function row(Website $site, int $daysAgo, string $cause = 'quota'): array
    {
        $when = now('UTC')->subDays($daysAgo)->startOfHour()->format('Y-m-d H:i:s');

        return ['website_id' => $site->id, 'bucket_start' => $when, 'cause' => $cause, 'hits' => 1, 'first_at' => $when, 'last_at' => $when];
    }

    public function test_the_purge_command_keeps_seven_days_and_removes_the_rest(): void
    {
        $site = Website::factory()->create();
        DB::table('collection_diagnostics')->insert([$this->row($site, 30), $this->row($site, 8, 'consent_missing'), $this->row($site, 6), $this->row($site, 0, 'domain_not_allowed')]);

        $this->artisan('perysee:prune-diagnostics')->assertSuccessful();

        $this->assertEqualsCanonicalizing(['quota', 'domain_not_allowed'], DB::table('collection_diagnostics')->pluck('cause')->all());
    }

    public function test_the_purge_works_through_more_than_one_batch(): void
    {
        $site = Website::factory()->create();
        foreach (range(1, 5) as $hour) {
            $when = now('UTC')->subDays(20)->startOfDay()->addHours($hour)->format('Y-m-d H:i:s');
            DB::table('collection_diagnostics')->insert(['website_id' => $site->id, 'bucket_start' => $when, 'cause' => 'quota', 'hits' => 1, 'first_at' => $when, 'last_at' => $when]);
        }

        $this->artisan('perysee:prune-diagnostics', ['--limit' => 2])->assertSuccessful();

        $this->assertSame(0, DB::table('collection_diagnostics')->count());
        $this->artisan('perysee:prune-diagnostics', ['--limit' => 0])->assertFailed();
    }

    public function test_an_empty_table_costs_one_read(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $this->artisan('perysee:prune-diagnostics')->assertSuccessful();

        $this->assertCount(1, $queries, implode(' | ', $queries));
    }

    public function test_the_purge_is_scheduled_hourly_and_never_overlaps(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command, 'perysee:prune-diagnostics'));

        $this->assertNotNull($event);
        $this->assertSame('0 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_deleting_a_site_removes_its_diagnostics_and_only_its_own(): void
    {
        $site = Website::factory()->create();
        $other = Website::factory()->create();
        DB::table('collection_diagnostics')->insert([$this->row($site, 0), $this->row($site, 0, 'consent_missing'), $this->row($other, 0)]);
        DB::table('websites')->where('id', $site->id)->update(['deleting_at' => now()]);

        for ($i = 0; $i < 30; $i++) {
            $this->artisan('perysee:maintenance', ['--limit' => 100])->assertSuccessful();
        }

        $this->assertSame([$other->id], DB::table('collection_diagnostics')->pluck('website_id')->map(fn ($id) => (int) $id)->all());
        $this->assertFalse(DB::table('websites')->where('id', $site->id)->exists());
    }
}
