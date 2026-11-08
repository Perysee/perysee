<?php

namespace Tests\Feature;

use App\Analytics\InstanceHealth;
use App\Installation\InstallationState;
use App\Models\Website;
use App\Support\CacheCoordination;
use App\Support\Coordination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CoordinationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
    }

    private function recorder(bool $allow = true): Coordination
    {
        return new class($allow) implements Coordination
        {
            /** @var list<array{string, string, int, int}|array{string, string}> */
            public array $calls = [];

            public function __construct(private bool $allow) {}

            public function throttle(string $key, int $maxAttempts, int $decaySeconds): bool
            {
                $this->calls[] = ['throttle', $key, $maxAttempts, $decaySeconds];

                return $this->allow;
            }

            public function failure(string $name): void
            {
                $this->calls[] = ['failure', $name];
            }

            public function failures(string $name): int
            {
                return 7;
            }
        };
    }

    private function collect(Website $site, array $extra = [])
    {
        return $this->withHeader('Origin', 'https://'.$site->domain)->postJson('/api/collect', array_merge(['v' => 1, 'site' => $site->public_id, 'type' => 'pageview', 'mode' => 'pageviews', 'host' => $site->domain, 'path' => '/', 'consent' => true], $extra));
    }

    public function test_the_default_implementation_is_the_cache_backed_one(): void
    {
        $this->assertInstanceOf(CacheCoordination::class, app(Coordination::class));
    }

    public function test_the_default_throttle_allows_up_to_the_limit_then_refuses_without_counting_more(): void
    {
        $coordination = new CacheCoordination;

        $this->assertTrue($coordination->throttle('t:a', 2, 60));
        $this->assertTrue($coordination->throttle('t:a', 2, 60));
        $this->assertFalse($coordination->throttle('t:a', 2, 60));
        $this->assertTrue($coordination->throttle('t:b', 2, 60));
    }

    public function test_the_default_failure_counters_use_the_cache(): void
    {
        $coordination = new CacheCoordination;

        $coordination->failure('collection_failures');
        $coordination->failure('collection_failures');

        $this->assertSame(2, $coordination->failures('collection_failures'));
        $this->assertSame(2, (int) Cache::get('analytics:collection_failures'));
    }

    public function test_collection_is_throttled_through_the_bound_service(): void
    {
        $site = Website::factory()->create();
        $recorder = $this->recorder(allow: false);
        $this->app->instance(Coordination::class, $recorder);

        $this->collect($site)->assertStatus(429);

        $this->assertSame('throttle', $recorder->calls[0][0]);
        $this->assertSame([120, 60], [$recorder->calls[0][2], $recorder->calls[0][3]]);
        $this->assertSame(['failure', 'collection_rejections'], $recorder->calls[1]);
        $this->assertDatabaseCount('events', 0);
    }

    public function test_the_collection_limit_is_a_setting_that_defaults_to_120_a_minute(): void
    {
        $this->assertSame(['max_attempts' => 120, 'decay_seconds' => 60], config('perysee.collect.rate_limit'));
        config(['perysee.collect.rate_limit' => ['max_attempts' => 5, 'decay_seconds' => 30]]);
        $site = Website::factory()->create();
        $recorder = $this->recorder();
        $this->app->instance(Coordination::class, $recorder);

        $this->collect($site)->assertNoContent();

        $this->assertSame([5, 30], [$recorder->calls[0][2], $recorder->calls[0][3]]);
    }

    public function test_collection_failures_are_counted_through_the_bound_service(): void
    {
        $site = Website::factory()->create();
        $recorder = $this->recorder();
        $this->app->instance(Coordination::class, $recorder);

        $this->withHeader('Origin', 'https://elsewhere.example')->postJson('/api/collect', ['v' => 1, 'site' => $site->public_id, 'type' => 'pageview', 'mode' => 'pageviews', 'host' => $site->domain, 'path' => '/', 'consent' => true])->assertForbidden();

        $this->assertContains(['failure', 'collection_rejections'], $recorder->calls);
    }

    public function test_the_health_snapshot_reads_its_counters_from_the_bound_service(): void
    {
        $this->app->instance(Coordination::class, $this->recorder());

        $snapshot = app(InstanceHealth::class)->snapshot();

        $this->assertSame(7, $snapshot['collection_failures']);
        $this->assertSame(7, $snapshot['collection_rejections']);
    }
}
