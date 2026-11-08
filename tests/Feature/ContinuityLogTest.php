<?php

namespace Tests\Feature;

use App\Analytics\Continuity\ContinuityLog;
use App\Installation\InstallationState;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ContinuityLogTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Website $site;

    protected function setUp(): void
    {
        parent::setUp();
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
        $this->owner = User::factory()->create();
        $this->site = Website::factory()->create(['allowed_domains' => ['example.com'], 'domain' => 'example.com', 'tracking_mode' => 'pageviews', 'consent_required' => true]);
        $this->site->members()->attach($this->owner, ['role' => 'owner']);
    }

    /** The edit form as the browser sends it: everything unchanged unless overridden. */
    private function save(array $changes = []): void
    {
        $this->actingAs($this->owner)->put('/sites/'.$this->site->id, array_merge([
            'name' => $this->site->name, 'domain' => 'example.com', 'allowed_domains' => ['example.com'], 'timezone' => 'UTC',
            'tracking_mode' => 'pageviews', 'consent_required' => '1',
        ], $changes))->assertSessionHasNoErrors();
    }

    public function test_changing_consent_records_one_break_entry(): void
    {
        $this->save(['consent_required' => '0']);

        $rows = DB::table('continuity_log')->get();
        $this->assertCount(1, $rows);
        $this->assertSame('consent', $rows[0]->kind);
        $this->assertSame('breaks', $rows[0]->severity);
        $this->assertSame($this->site->id, (int) $rows[0]->website_id);
        $this->assertSame($this->owner->id, (int) $rows[0]->actor_id);
        $this->assertSame(['from' => true, 'to' => false], json_decode($rows[0]->payload, true));
        $this->assertSame('all', $rows[0]->scope);
        $this->assertSame(now('UTC')->toDateString(), $rows[0]->data_from);
    }

    public function test_saving_without_a_change_or_renaming_the_site_records_nothing(): void
    {
        $this->save();
        $this->save(['name' => 'A new name']);

        $this->assertSame('A new name', $this->site->fresh()->name);
        $this->assertSame(0, DB::table('continuity_log')->count());
    }

    public function test_the_same_change_submitted_twice_is_recorded_once(): void
    {
        $this->save(['consent_required' => '0']);
        $this->save(['consent_required' => '0']);

        $this->assertSame(1, DB::table('continuity_log')->count());
    }

    public function test_changing_several_fields_records_one_entry_per_kind(): void
    {
        $this->save(['consent_required' => '0', 'currency' => 'EUR', 'excluded_paths' => ['/admin'], 'allowed_domains' => ['example.com', 'www.example.com']]);

        $this->assertEqualsCanonicalizing(['consent', 'currency', 'excluded_paths', 'allowed_domains'], DB::table('continuity_log')->pluck('kind')->all());
        $this->assertSame(['events'], DB::table('continuity_log')->where('kind', 'currency')->pluck('scope')->all());
    }

    public function test_the_measurement_mode_is_written_to_the_log_and_to_the_measurement_periods(): void
    {
        DB::table('site_measurement_periods')->insert(['website_id' => $this->site->id, 'started_at' => '2026-10-01 00:00:00', 'mode' => 'pageviews']);

        $this->save(['tracking_mode' => 'sessions']);

        $entry = DB::table('continuity_log')->where('kind', 'tracking_mode')->sole();
        $this->assertSame('breaks', $entry->severity);
        $this->assertSame('sessions', $entry->scope);
        $this->assertSame(['from' => 'pageviews', 'to' => 'sessions'], json_decode($entry->payload, true));
        $this->assertSame(['pageviews', 'sessions'], DB::table('site_measurement_periods')->where('website_id', $this->site->id)->orderBy('id')->pluck('mode')->all());
        $this->assertNull(DB::table('site_measurement_periods')->where('mode', 'sessions')->value('ended_at'));
    }

    public function test_a_host_added_is_a_note_and_a_host_removed_is_a_break(): void
    {
        $this->save(['allowed_domains' => ['example.com', 'www.example.com']]);
        $this->save(['allowed_domains' => ['example.com']]);

        $this->assertSame(['note', 'breaks'], DB::table('continuity_log')->where('kind', 'allowed_domains')->orderBy('id')->pluck('severity')->all());
    }

    public function test_a_reordered_list_is_not_a_change(): void
    {
        $this->save(['excluded_paths' => ['/a', '/b']]);
        $this->save(['excluded_paths' => ['/b', '/a']]);

        $this->assertSame(1, DB::table('continuity_log')->count());
    }

    public function test_a_failed_update_leaves_no_entry(): void
    {
        DB::table('websites')->where('id', $this->site->id)->update(['deleting_at' => now('UTC')]);

        $this->actingAs($this->owner)->put('/sites/'.$this->site->id, ['name' => 'x', 'domain' => 'example.com', 'allowed_domains' => ['example.com'], 'timezone' => 'UTC', 'consent_required' => '0'])->assertStatus(409);

        $this->assertSame(0, DB::table('continuity_log')->count());
    }

    public function test_a_payload_never_exceeds_one_kibibyte_and_lists_keep_a_count(): void
    {
        $paths = array_map(fn (int $index) => '/'.str_repeat('é', 60).$index, range(1, 50));

        $this->save(['excluded_paths' => $paths]);

        $payload = DB::table('continuity_log')->where('kind', 'excluded_paths')->value('payload');
        $this->assertLessThanOrEqual(1024, strlen($payload));
        $decoded = json_decode($payload, true);
        $this->assertSame(50, $decoded['to_count']);
        $this->assertSame(0, $decoded['from_count']);
        $this->assertLessThan(50, count($decoded['to']));
    }

    public function test_text_is_cut_and_invalid_utf8_is_cleaned_before_it_is_encoded(): void
    {
        app(ContinuityLog::class)->record('currency', $this->site->id, 'note', 'events', ['from' => "ab\xC3\x28cd", 'to' => str_repeat('x', 500), 'list' => ["\xFF\xFE", 'ok']]);

        $payload = DB::table('continuity_log')->value('payload');
        $this->assertTrue(mb_check_encoding($payload, 'UTF-8'));
        $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('ab?(cd', $decoded['from']);
        $this->assertSame(64, mb_strlen($decoded['to']));
        $this->assertSame(['??', 'ok'], $decoded['list']);
    }

    public function test_an_unknown_kind_severity_or_scope_is_refused(): void
    {
        $log = app(ContinuityLog::class);

        foreach ([['nope', 'note', 'all'], ['consent', 'fatal', 'all'], ['consent', 'note', 'everything']] as [$kind, $severity, $scope]) {
            try {
                $log->record($kind, $this->site->id, $severity, $scope, []);
                $this->fail('An unknown value must be refused.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, DB::table('continuity_log')->count());
    }

    public function test_breaks_since_returns_the_breaks_of_the_site_and_of_the_instance_inside_the_window(): void
    {
        $other = Website::factory()->create();
        $log = app(ContinuityLog::class);
        $this->travelTo(now('UTC')->setDate(2026, 11, 1)->setTime(10, 0));
        $log->record('consent', $this->site->id, 'breaks', 'all', ['from' => true, 'to' => false], '2026-11-01');
        $log->record('timezone', $this->site->id, 'note', 'all', ['from' => 'UTC', 'to' => 'Europe/Paris']);
        $log->record('consent', $other->id, 'breaks', 'all', ['from' => true, 'to' => false]);
        $this->travelTo(now('UTC')->setDate(2026, 11, 3));
        $log->record('retention_change', null, 'breaks', 'all', ['setting' => 'stats_retention_days', 'from' => 1095, 'to' => 365]);
        $this->travelTo(now('UTC')->setDate(2026, 12, 1));
        $log->record('currency', $this->site->id, 'breaks', 'events', ['from' => null, 'to' => 'EUR']);

        $breaks = $log->breaksSince($this->site, now('UTC')->setDate(2026, 10, 31), now('UTC')->setDate(2026, 11, 30));

        $this->assertSame(['consent', 'retention_change'], array_column($breaks, 'kind'));
        $this->assertSame('2026-11-01', $breaks[0]['data_from']);
        $this->assertSame(['from' => true, 'to' => false], $breaks[0]['payload']);
        $this->assertNull($breaks[1]['data_from']);
    }

    public function test_the_log_costs_one_insert_per_tracked_change_and_nothing_otherwise(): void
    {
        $this->assertSame(0, $this->logInserts(fn () => $this->save(['name' => 'Renamed'])));
        $this->assertSame(1, $this->logInserts(fn () => $this->save(['consent_required' => '0'])));
        $this->assertSame(2, $this->logInserts(fn () => $this->save(['consent_required' => '1', 'currency' => 'EUR'])));
    }

    public function test_deleting_a_site_removes_its_entries_and_only_its_own(): void
    {
        $other = Website::factory()->create();
        $log = app(ContinuityLog::class);
        $log->record('consent', $this->site->id, 'breaks', 'all', []);
        $log->record('consent', $other->id, 'breaks', 'all', []);
        $log->record('retention_change', null, 'note', 'all', []);
        DB::table('websites')->where('id', $this->site->id)->update(['deleting_at' => now()]);

        for ($i = 0; $i < 30; $i++) {
            $this->artisan('perysee:maintenance', ['--limit' => 100])->assertSuccessful();
        }

        $this->assertEqualsCanonicalizing([$other->id, null], DB::table('continuity_log')->pluck('website_id')->map(fn ($id) => $id === null ? null : (int) $id)->all());
    }

    public function test_between_lists_the_entries_of_the_period_without_their_payload(): void
    {
        $other = Website::factory()->create();
        $log = app(ContinuityLog::class);
        $log->record('consent', $this->site->id, 'breaks', 'all', ['from' => true, 'to' => false], '2026-10-20');
        $log->record('timezone', $this->site->id, 'note', 'all', [], '2026-10-10');
        $log->record('retention_change', null, 'breaks', 'all', [], '2026-10-24');
        $log->record('consent', $other->id, 'breaks', 'all', [], '2026-10-21');
        $log->record('currency', $this->site->id, 'breaks', 'events', [], '2026-10-25');

        $entries = $log->between($this->site, '2026-10-18', '2026-10-24');

        $this->assertSame([
            ['at' => '2026-10-20', 'kind' => 'consent', 'scope' => 'all', 'severity' => 'breaks'],
            ['at' => '2026-10-24', 'kind' => 'retention_change', 'scope' => 'all', 'severity' => 'breaks'],
        ], $entries);
    }

    public function test_an_undated_entry_applies_on_the_local_day_it_was_written(): void
    {
        $this->site->forceFill(['timezone' => 'Asia/Tokyo'])->save();
        DB::table('continuity_log')->insert(['website_id' => $this->site->id, 'occurred_at' => '2026-10-20 18:30:00.000', 'kind' => 'consent', 'severity' => 'breaks', 'scope' => 'all', 'payload' => '{}']);

        $entries = app(ContinuityLog::class)->between($this->site->fresh(), '2026-10-21', '2026-10-21');

        $this->assertSame(['2026-10-21'], array_column($entries, 'at'));
        $this->assertSame([], app(ContinuityLog::class)->between($this->site->fresh(), '2026-10-20', '2026-10-20'));
    }

    public function test_the_csv_says_since_when_the_figures_are_not_comparable(): void
    {
        $log = app(ContinuityLog::class);
        $this->actingAs($this->owner);
        $csv = fn (string $query = ''): string => $this->get('/sites/'.$this->site->id.'/export?from=2026-10-18&to=2026-10-24'.$query)->assertOk()->streamedContent();
        $this->assertStringStartsWith('date,', $csv());

        $log->record('timezone', $this->site->id, 'note', 'all', [], '2026-10-19');
        $log->record('retention_purge', $this->site->id, 'breaks', 'goals', [], '2026-10-19');
        $log->record('consent', $this->site->id, 'breaks', 'all', [], '2026-10-25');
        $this->assertStringStartsWith('date,', $csv(), 'a note, a goals-only break or a later one say nothing');

        $log->record('consent', $this->site->id, 'breaks', 'all', [], '2026-10-20');
        $log->record('tracking_mode', $this->site->id, 'breaks', 'sessions', [], '2026-10-22');
        $this->assertStringStartsWith("\"# not comparable before 2026-10-22\"\ndate,pageviews,", $csv());
        $this->assertStringStartsWith("\"# not comparable before 2026-10-22\"\nvalue,count", $csv('&dimension=path'));
    }

    public function test_the_comment_line_comes_after_the_demonstration_line_and_cannot_carry_a_formula(): void
    {
        $this->site->forceFill(['is_demo' => true])->save();
        app(ContinuityLog::class)->record('consent', $this->site->id, 'breaks', 'all', [], '2026-10-20');

        $lines = explode("\n", $this->actingAs($this->owner)->get('/sites/'.$this->site->id.'/export?from=2026-10-18&to=2026-10-24')->streamedContent());

        $this->assertSame('"# demonstration data: synthetic events"', $lines[0]);
        $this->assertSame('"# not comparable before 2026-10-20"', $lines[1]);
        $this->assertMatchesRegularExpression('/^"# not comparable before \d{4}-\d{2}-\d{2}"$/', $lines[1]);
    }

    private function logInserts(callable $work): int
    {
        $inserts = 0;
        DB::listen(function ($query) use (&$inserts): void {
            if (preg_match('/^insert into [`"]continuity_log[`"]/i', $query->sql)) {
                $inserts++;
            }
        });
        $work();

        return $inserts;
    }
}
