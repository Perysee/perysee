<?php

namespace Tests\Feature;

use App\Installation\InstallationState;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Pins the cost of the collection hot path (COL-01): an accepted event is one short transaction around one
 * read of the site and one insert. Diagnostics must never add a query to it.
 */
class CollectionQueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
    }

    private function payload(Website $site, array $extra = []): array
    {
        return array_merge(['v' => 1, 'site' => $site->public_id, 'type' => 'pageview', 'mode' => 'pageviews', 'host' => $site->domain, 'path' => '/pricing', 'consent' => true], $extra);
    }

    /** @return list<string> */
    private function observe(callable $work): array
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });
        $work();

        return $queries;
    }

    /** Statements that are not transaction control (SQLite and MariaDB do not report BEGIN and COMMIT the same way). */
    private function statements(array $queries): array
    {
        return array_values(array_filter($queries, fn (string $sql): bool => ! preg_match('/^(begin|commit|rollback|savepoint|release)/', $sql)));
    }

    public function test_an_accepted_event_is_one_site_read_and_one_insert(): void
    {
        $site = Website::factory()->create(['first_collected_at' => now('UTC')]);
        $queries = $this->observe(fn () => $this->withHeader('Origin', 'https://'.$site->domain)->postJson('/api/collect', $this->payload($site))->assertNoContent());

        $statements = $this->statements($queries);
        $this->assertCount(2, $statements, implode(' | ', $queries));
        $this->assertStringStartsWith('select', $statements[0]);
        $this->assertStringContainsString('websites', $statements[0]);
        $this->assertStringStartsWith('insert into', $statements[1]);
        $this->assertStringContainsString('events', $statements[1]);
    }

    public function test_the_first_event_of_a_site_adds_exactly_one_update(): void
    {
        $site = Website::factory()->create(['first_collected_at' => null]);
        $queries = $this->observe(fn () => $this->withHeader('Origin', 'https://'.$site->domain)->postJson('/api/collect', $this->payload($site))->assertNoContent());

        $statements = $this->statements($queries);
        $this->assertCount(3, $statements, implode(' | ', $queries));
        $this->assertStringStartsWith('update', $statements[1]);
        $this->assertNotNull($site->fresh()->first_collected_at);
    }

    public function test_the_transaction_count_of_an_accepted_event_is_one(): void
    {
        $site = Website::factory()->create(['first_collected_at' => now('UTC')]);
        $transactions = 0;
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Database\Events\TransactionBeginning::class, function () use (&$transactions): void {
            $transactions++;
        });
        $this->withHeader('Origin', 'https://'.$site->domain)->postJson('/api/collect', $this->payload($site))->assertNoContent();

        $this->assertSame(1, $transactions);
    }

    public function test_a_rejected_event_never_writes_to_events(): void
    {
        $site = Website::factory()->create();
        $queries = $this->observe(fn () => $this->withHeader('Origin', 'https://outsider.example')->postJson('/api/collect', $this->payload($site))->assertForbidden());

        $this->assertEmpty(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'into "events"') || str_contains($sql, 'into `events`')), implode(' | ', $queries));
        $this->assertSame(0, DB::table('events')->count());
    }
}
