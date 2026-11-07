<?php

namespace Tests\Feature;

use App\Analytics\Ingestion\RejectionLog;
use App\Analytics\InstanceHealth;
use App\Installation\InstallationState;
use App\Models\Website;
use App\Support\Coordination;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CollectionRejectionTest extends TestCase
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

    private function send(Website $site, array $extra = [], ?string $origin = null)
    {
        return $this->withHeader('Origin', $origin ?? 'https://'.$site->domain)->postJson('/api/collect', $this->payload($site, $extra));
    }

    private function rows(?Website $site = null): Collection
    {
        return DB::table('collection_diagnostics')->when($site, fn ($query) => $query->where('website_id', $site->id))->get();
    }

    public function test_a_foreign_domain_is_filed_with_its_host_and_nothing_else(): void
    {
        $site = Website::factory()->create();
        foreach (range(1, 3) as $ignored) {
            $this->send($site, [], 'https://Blog.Outsider.example:8443')->assertForbidden();
        }

        $rows = $this->rows($site);
        $this->assertCount(1, $rows);
        $this->assertSame('domain_not_allowed', $rows[0]->cause);
        $this->assertSame(3, (int) $rows[0]->hits);
        $this->assertSame('blog.outsider.example', $rows[0]->sample_host);
    }

    public function test_each_cause_is_recorded_under_its_site(): void
    {
        $site = Website::factory()->create(['excluded_paths' => ['/private*']]);
        $this->send($site, ['consent' => false])->assertForbidden();
        $this->send($site, ['mode' => 'sessions', 'session' => str_repeat('a', 32)])->assertStatus(422);
        $this->send($site, ['session' => str_repeat('a', 32)])->assertStatus(422);
        $this->send($site, ['path' => '/private/area'])->assertForbidden();
        RateLimiter::increment('collect:'.$site->id.':'.hash_hmac('sha256', '127.0.0.1', config('app.key')), 60, 120);
        $this->send($site)->assertStatus(429);
        $this->send($site, ['v' => 2])->assertStatus(422);
        $this->send($site, ['email' => 'a@example.com'])->assertStatus(422);

        $causes = $this->rows($site)->pluck('hits', 'cause')->all();
        $this->assertEquals(['consent_missing' => 1, 'mode_mismatch' => 2, 'path_excluded' => 1, 'quota' => 1, 'invalid_payload' => 2], $causes);
        $this->assertSame(0, $this->rows()->where('website_id', 0)->count());
    }

    public function test_shape_failures_without_a_site_are_filed_under_the_instance(): void
    {
        $site = Website::factory()->create();
        $origin = 'https://'.$site->domain;
        $this->withHeader('Origin', $origin)->postJson('/api/collect', $this->payload($site, ['path' => str_repeat('a', 9000)]))->assertStatus(413);
        $this->call('POST', '/api/collect', server: ['CONTENT_TYPE' => 'text/html', 'HTTP_ORIGIN' => $origin], content: '{}')->assertStatus(415);
        $this->call('POST', '/api/collect', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => $origin], content: '[1')->assertStatus(422);

        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertSame(0, (int) $rows[0]->website_id);
        $this->assertSame('invalid_payload', $rows[0]->cause);
        $this->assertSame(3, (int) $rows[0]->hits);
    }

    public function test_an_invalid_payload_naming_a_real_site_is_filed_under_that_site(): void
    {
        $site = Website::factory()->create();
        $this->send($site, ['v' => 2])->assertStatus(422);

        $this->assertSame(1, $this->rows($site)->count());
        $this->assertSame(0, $this->rows()->where('website_id', 0)->count());
    }

    public function test_the_invalid_noise_of_one_site_does_not_starve_the_evidence_of_another(): void
    {
        $noisy = Website::factory()->create();
        $quiet = Website::factory()->create();
        foreach (range(1, RejectionLog::WRITES_PER_MINUTE + 5) as $ignored) {
            $this->send($noisy, ['v' => 2])->assertStatus(422);
        }
        $this->send($quiet, ['v' => 2])->assertStatus(422);

        $this->assertSame(1, $this->rows($quiet)->count());
        $this->assertSame(RejectionLog::WRITES_PER_MINUTE, (int) $this->rows($noisy)->first()->hits);
    }

    public function test_a_forged_site_key_is_never_stored(): void
    {
        $site = Website::factory()->create();
        $forged = '99999999-9999-4999-8999-999999999999';
        $this->send($site, ['site' => $forged], 'https://stranger.example')->assertNotFound();
        $this->send($site, ['site' => $forged, 'v' => 2], 'https://stranger.example')->assertStatus(422);

        $this->assertEqualsCanonicalizing(['unknown_site', 'invalid_payload'], $this->rows()->pluck('cause')->all());
        foreach ($this->rows() as $row) {
            $this->assertSame(0, (int) $row->website_id);
            $this->assertStringNotContainsString($forged, json_encode($row));
        }
        $this->assertSame('stranger.example', $this->rows()->firstWhere('cause', 'unknown_site')->sample_host);
    }

    public function test_a_site_being_deleted_is_reported_as_unknown_without_its_key(): void
    {
        $site = Website::factory()->create(['deleting_at' => now('UTC')]);
        $this->send($site)->assertNotFound();

        $this->assertSame([0], $this->rows()->pluck('website_id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_the_table_keeps_no_address_agent_path_or_payload(): void
    {
        $columns = Schema::getColumnListing('collection_diagnostics');
        sort($columns);
        $this->assertSame(['bucket_start', 'cause', 'first_at', 'hits', 'last_at', 'sample_host', 'website_id'], $columns);

        $site = Website::factory()->create();
        $this->withHeaders(['Origin' => 'https://outsider.example', 'User-Agent' => 'SecretAgent/9', 'X-Forwarded-For' => '203.0.113.77'])->postJson('/api/collect', $this->payload($site, ['path' => '/secret?token=abc']))->assertForbidden();
        $dump = json_encode($this->rows());
        foreach (['SecretAgent', '203.0.113.77', '127.0.0.1', '/secret', 'token', $site->public_id] as $needle) {
            $this->assertStringNotContainsString($needle, $dump);
        }
    }

    public function test_a_hostile_origin_is_not_stored_as_a_host(): void
    {
        $site = Website::factory()->create();
        foreach (['https://exa mple.com', 'https://under_score.example', 'null', 'https://'.str_repeat('a', 300).'.example'] as $origin) {
            $this->send($site, [], $origin)->assertForbidden();
        }

        $this->assertNull($this->rows($site)->first()->sample_host);
    }

    public function test_a_flood_costs_at_most_twenty_writes_a_minute_and_the_counter_keeps_counting(): void
    {
        $site = Website::factory()->create();
        $before = app(Coordination::class)->failures('collection_rejections');
        foreach (range(1, 60) as $ignored) {
            $this->send($site, [], 'https://outsider.example')->assertForbidden();
        }

        $this->assertSame(20, (int) $this->rows($site)->sum('hits'));
        $this->assertSame($before + 60, app(Coordination::class)->failures('collection_rejections'));
        $this->assertSame(0, DB::table('events')->count());
    }

    public function test_a_failing_recorder_does_not_change_the_answer(): void
    {
        $site = Website::factory()->create();
        // No DDL here: on MariaDB it would end the test transaction and destroy the shared table.
        Event::listen(QueryExecuted::class, function ($query): void {
            if (str_contains($query->sql, 'collection_diagnostics')) {
                throw new \RuntimeException('diagnostics are unavailable');
            }
        });

        $this->send($site, [], 'https://outsider.example')->assertForbidden();
        $this->send($site, ['v' => 2])->assertStatus(422);
        $this->send($site)->assertNoContent();
    }

    public function test_an_accepted_event_writes_no_diagnostic(): void
    {
        $site = Website::factory()->create();
        $this->send($site)->assertNoContent();

        $this->assertSame(0, $this->rows()->count());
    }

    public function test_the_migration_can_run_twice(): void
    {
        $migration = require base_path('database/migrations/2026_11_08_100000_create_collection_diagnostics_table.php');
        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasTable('collection_diagnostics'));
    }

    public function test_expired_rows_are_pruned_and_recent_ones_kept(): void
    {
        $site = Website::factory()->create();
        $row = fn (string $when, string $cause) => ['website_id' => $site->id, 'bucket_start' => $when, 'cause' => $cause, 'hits' => 1, 'first_at' => $when, 'last_at' => $when];
        DB::table('collection_diagnostics')->insert([$row(now('UTC')->subDays(8)->startOfHour()->format('Y-m-d H:i:s'), 'quota'), $row(now('UTC')->subDays(6)->startOfHour()->format('Y-m-d H:i:s'), 'quota')]);

        $this->assertSame(1, app(RejectionLog::class)->prune());
        $this->assertSame(1, $this->rows($site)->count());
        $this->assertSame(0, app(RejectionLog::class)->prune());
    }

    public function test_the_health_page_counts_shape_rejections_too(): void
    {
        $site = Website::factory()->create();
        $before = app(InstanceHealth::class)->snapshot()['collection_rejections'];
        $this->send($site, ['v' => 2])->assertStatus(422);

        $this->assertSame($before + 1, app(InstanceHealth::class)->snapshot()['collection_rejections']);
    }
}
