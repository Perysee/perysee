<?php

namespace Tests\Feature;

use App\Installation\InstallationState;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class EventOrderDeduplicationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
    }

    private function event(Website $site, array $extra = []): TestResponse
    {
        $payload = array_merge(['v' => 1, 'site' => $site->public_id, 'type' => 'event', 'mode' => 'pageviews', 'host' => $site->domain, 'path' => '/thanks', 'consent' => true, 'event_name' => 'purchase', 'value' => 10], $extra);

        return $this->withHeader('Origin', 'https://'.$site->domain)->postJson('/api/collect', $payload);
    }

    public function test_a_second_event_with_the_same_order_is_acknowledged_but_not_stored(): void
    {
        $site = Website::factory()->create();

        $this->event($site, ['id' => 'order-1001'])->assertNoContent()->assertHeaderMissing('X-Perysee-Duplicate');
        $this->event($site, ['id' => 'order-1001'])->assertNoContent()->assertHeader('X-Perysee-Duplicate', 'order');

        $this->assertSame(1, DB::table('events')->where('website_id', $site->id)->count());
        $this->assertSame(1, DB::table('event_order_ids')->where('website_id', $site->id)->count());
    }

    public function test_the_identifier_is_never_stored_in_clear(): void
    {
        $site = Website::factory()->create();

        $this->event($site, ['id' => 'order-1001'])->assertNoContent();

        $row = DB::table('event_order_ids')->first();
        $this->assertSame(32, strlen($row->order_hash));
        $this->assertStringNotContainsString('1001', json_encode([$row, DB::table('events')->first()]));
    }

    public function test_orders_are_unique_per_site_and_per_event_name(): void
    {
        $a = Website::factory()->create();
        $b = Website::factory()->create();

        $this->event($a, ['id' => 'same'])->assertNoContent();
        $this->event($b, ['id' => 'same'])->assertNoContent();
        $this->event($a, ['id' => 'same', 'event_name' => 'refund'])->assertNoContent();
        $this->event($a, ['id' => 'other'])->assertNoContent();
        $this->event($a, ['id' => 'SAME'])->assertNoContent();

        $this->assertSame(5, DB::table('events')->count());
        $this->assertSame(5, DB::table('event_order_ids')->count());
    }

    public function test_an_event_without_identifier_is_never_deduplicated(): void
    {
        $site = Website::factory()->create();

        $this->event($site)->assertNoContent();
        $this->event($site)->assertNoContent();

        $this->assertSame(2, DB::table('events')->count());
        $this->assertSame(0, DB::table('event_order_ids')->count());
    }

    public function test_only_an_event_that_carries_an_identifier_pays_for_the_extra_write(): void
    {
        $site = Website::factory()->create();
        $this->event($site)->assertNoContent();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->event($site)->assertNoContent();
        $plain = count($queries);
        $queries = [];
        $this->event($site, ['id' => 'o-1'])->assertNoContent();

        $this->assertSame($plain + 1, count($queries), implode(' | ', $queries));
        $this->assertEmpty(array_filter($queries, fn (string $sql): bool => (bool) preg_match('/^select .*event_order_ids/i', $sql)), 'The collector must not read before writing.');
    }

    public function test_a_failed_insert_does_not_burn_the_order(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('The failing trigger is SQLite syntax.');
        }
        $site = Website::factory()->create();
        DB::statement('create trigger fail_events before insert on events begin select raise(abort, \'boom\'); end');

        $this->event($site, ['id' => 'o-1'])->assertStatus(503);
        $this->assertSame(0, DB::table('event_order_ids')->count());
        DB::statement('drop trigger fail_events');

        $this->event($site, ['id' => 'o-1'])->assertNoContent();
        $this->assertSame(1, DB::table('events')->count());
    }

    public function test_invalid_identifiers_refuse_the_event(): void
    {
        $site = Website::factory()->create();

        foreach ([str_repeat('a', 65), '', 'has space', "tab\t", 12, ['a'], 'é-accent'] as $bad) {
            $this->event($site, ['id' => $bad])->assertUnprocessable();
        }
        $this->event($site, ['id' => str_repeat('a', 64)])->assertNoContent();
        $this->assertSame(1, DB::table('events')->count());
    }

    public function test_a_deduplicated_event_keeps_its_value_out_of_the_reports(): void
    {
        $site = Website::factory()->create();
        $this->event($site, ['id' => 'o-1', 'value' => 20])->assertNoContent();
        $this->event($site, ['id' => 'o-1', 'value' => 20])->assertNoContent();

        $this->assertSame(20000, (int) DB::table('events')->sum('event_value'));
    }
}
