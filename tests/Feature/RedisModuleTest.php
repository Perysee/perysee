<?php

namespace Tests\Feature;

use App\Installation\InstallationState;
use App\Models\User;
use App\Models\Website;
use App\Modules\ModuleExtensions;
use App\Modules\ModuleLoader;
use App\Modules\ModuleRegistry;
use App\Support\CacheCoordination;
use App\Support\Coordination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Perysee\Modules\Redis\RedisCoordination;
use Predis\Client;
use Tests\TestCase;

class RedisModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
        config(['perysee.modules_path' => base_path('modules')]);
        $this->clearPause();
    }

    protected function tearDown(): void
    {
        $this->clearPause();
        parent::tearDown();
    }

    private function pauseFile(): string
    {
        return storage_path('framework/redis-module-paused');
    }

    private function clearPause(): void
    {
        if (is_file($this->pauseFile())) {
            unlink($this->pauseFile());
        }
    }

    /**
     * @param  array<string, mixed>  $connection
     */
    private function boot(bool $enabled, array $connection = []): void
    {
        config(['perysee.redis_enabled' => $enabled, 'database.redis.default' => $connection + ['host' => '127.0.0.1', 'port' => 1, 'password' => null, 'database' => 0]]);
        app()->forgetInstance(ModuleRegistry::class);
        app()->forgetInstance(ModuleLoader::class);
        app()->forgetInstance(Coordination::class);
        app(ModuleRegistry::class)->enable('redis');
        app(ModuleLoader::class)->load();
    }

    /**
     * @return array<string, mixed>
     */
    private function server(): array
    {
        $port = getenv('PERYSEE_TEST_REDIS_PORT');
        if ($port === false) {
            $this->markTestSkipped('Set PERYSEE_TEST_REDIS_PORT (and PERYSEE_TEST_REDIS_PASSWORD) to run against a Redis server.');
        }

        return ['host' => getenv('PERYSEE_TEST_REDIS_HOST') ?: '127.0.0.1', 'port' => (int) $port, 'password' => getenv('PERYSEE_TEST_REDIS_PASSWORD') ?: null, 'database' => 15];
    }

    public function test_it_loads_without_error_and_changes_nothing_until_it_is_switched_on(): void
    {
        $this->boot(enabled: false);

        $this->assertSame([], app(ModuleLoader::class)->failures());
        $this->assertInstanceOf(CacheCoordination::class, app(Coordination::class));
    }

    public function test_switched_on_it_replaces_the_coordination_service(): void
    {
        $this->boot(enabled: true);

        $this->assertInstanceOf(RedisCoordination::class, app(Coordination::class));
    }

    public function test_an_unreachable_server_never_stops_collection_and_is_skipped_afterwards(): void
    {
        $this->boot(enabled: true);
        $site = Website::factory()->create();
        $request = fn () => $this->withHeader('Origin', 'https://'.$site->domain)->postJson('/api/collect', ['v' => 1, 'site' => $site->public_id, 'type' => 'pageview', 'mode' => 'pageviews', 'host' => $site->domain, 'path' => '/', 'consent' => true]);

        $request()->assertNoContent();
        $this->assertTrue(is_file($this->pauseFile()));
        $request()->assertNoContent();

        $this->assertSame(2, DB::table('events')->count());
        $this->assertGreaterThanOrEqual(1, (int) Cache::get('redis-module:fallbacks'));
    }

    public function test_an_unreachable_server_still_throttles_through_the_cache(): void
    {
        $this->boot(enabled: true);
        $coordination = app(Coordination::class);

        $this->assertTrue($coordination->throttle('collect:x', 2, 60));
        $this->assertTrue($coordination->throttle('collect:x', 2, 60));
        $this->assertFalse($coordination->throttle('collect:x', 2, 60));
    }

    public function test_the_health_card_says_when_the_module_is_not_switched_on(): void
    {
        $this->boot(enabled: false);

        $card = app(ModuleExtensions::class)->healthCards()[0];

        $this->assertSame('redis', $card['module']);
        $this->assertSame('attention', $card['status']);
        $this->assertSame('Not switched on', $card['items'][0]['value']);
    }

    public function test_the_health_card_reports_an_unreachable_server_without_leaking_its_error(): void
    {
        $this->boot(enabled: true, connection: ['password' => 'secret-value']);

        $card = app(ModuleExtensions::class)->healthCards()[0];

        $this->assertSame('attention', $card['status']);
        $this->assertSame('Unreachable', $card['items'][0]['value']);
        $this->assertStringNotContainsString('secret-value', json_encode($card));
    }

    public function test_the_card_is_shown_to_administrators_on_the_health_page(): void
    {
        $this->boot(enabled: false);

        $this->actingAs(User::factory()->create(['is_admin' => true]))->get('/health')->assertOk()->assertInertia(fn ($page) => $page->where('moduleCards.0.module', 'redis'));
    }

    public function test_against_a_server_the_throttle_counts_allowed_attempts_only_and_expires(): void
    {
        $this->boot(enabled: true, connection: $this->server());
        $coordination = app(Coordination::class);
        $key = 'collect:test-'.bin2hex(random_bytes(4));

        $this->assertTrue($coordination->throttle($key, 2, 30));
        $this->assertTrue($coordination->throttle($key, 2, 30));
        $this->assertFalse($coordination->throttle($key, 2, 30));
        $this->assertFalse($coordination->throttle($key, 2, 30));
        $this->assertTrue($coordination->throttle($key.'-other', 2, 30));

        $client = new Client(['scheme' => 'tcp', 'host' => $this->server()['host'], 'port' => $this->server()['port'], 'password' => $this->server()['password'], 'database' => 15]);
        $this->assertSame('2', $client->get('perysee:throttle:'.$key));
        $this->assertGreaterThan(0, $client->ttl('perysee:throttle:'.$key));
        $this->assertLessThanOrEqual(30, $client->ttl('perysee:throttle:'.$key));
        $client->del(['perysee:throttle:'.$key, 'perysee:throttle:'.$key.'-other']);
        $this->assertFalse(is_file($this->pauseFile()));
    }

    public function test_against_a_server_failures_are_counted_and_read_back(): void
    {
        $this->boot(enabled: true, connection: $this->server());
        $coordination = app(Coordination::class);
        $name = 'test_failures_'.bin2hex(random_bytes(4));

        $this->assertSame(0, $coordination->failures($name));
        $coordination->failure($name);
        $coordination->failure($name);

        $this->assertSame(2, $coordination->failures($name));
        $client = new Client(['scheme' => 'tcp', 'host' => $this->server()['host'], 'port' => $this->server()['port'], 'password' => $this->server()['password'], 'database' => 15]);
        $this->assertGreaterThan(0, $client->ttl('perysee:failures:'.$name));
        $client->del(['perysee:failures:'.$name]);
    }

    public function test_against_a_server_the_health_card_is_working_and_reports_the_version(): void
    {
        $this->boot(enabled: true, connection: $this->server());

        $card = app(ModuleExtensions::class)->healthCards()[0];

        $this->assertSame('ok', $card['status']);
        $labels = array_column($card['items'], 'value', 'label');
        $this->assertSame('Connected', $labels['Connection']);
        $this->assertMatchesRegularExpression('/^\d+\.\d+/', $labels['Version']);
        $this->assertArrayHasKey('Latency', $labels);
    }
}
