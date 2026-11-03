<?php

declare(strict_types=1);

namespace Perysee\Modules\Redis;

use App\Support\CacheCoordination;
use App\Support\Coordination;
use Illuminate\Support\Facades\Cache;
use Predis\Client;
use Throwable;

/**
 * Keeps the collection rate limit and the failure counters in Redis. Redis is only an accelerator: when it
 * cannot be reached, every call is answered by the fallback (the application cache) and the server is not
 * tried again for a short time, so an outage never slows or stops collection.
 */
final class RedisCoordination implements Coordination
{
    private const PREFIX = 'perysee:';

    private const PAUSE_SECONDS = 30;

    private const FAILURE_TTL = 604800;

    /** Counts an attempt only when it is allowed, and sets the expiry with the first attempt of a window. */
    private const THROTTLE = <<<'LUA'
local count = tonumber(redis.call('GET', KEYS[1]) or '0')
if count >= tonumber(ARGV[1]) then
    return 0
end
if redis.call('INCR', KEYS[1]) == 1 then
    redis.call('EXPIRE', KEYS[1], ARGV[2])
end
return 1
LUA;

    private ?Client $client = null;

    /**
     * @param  array<string, mixed>  $connection  The "database.redis.default" settings.
     */
    public function __construct(private readonly array $connection, private readonly Coordination $fallback = new CacheCoordination) {}

    public function throttle(string $key, int $maxAttempts, int $decaySeconds): bool
    {
        return (bool) $this->attempt(
            fn (Client $client): bool => (int) $client->eval(self::THROTTLE, 1, self::PREFIX.'throttle:'.$key, $maxAttempts, $decaySeconds) === 1,
            fn (): bool => $this->fallback->throttle($key, $maxAttempts, $decaySeconds),
        );
    }

    public function failure(string $name): void
    {
        $this->attempt(function (Client $client) use ($name): bool {
            $key = self::PREFIX.'failures:'.$name;
            if ($client->incr($key) === 1) {
                $client->expire($key, self::FAILURE_TTL);
            }

            return true;
        }, function () use ($name): bool {
            $this->fallback->failure($name);

            return true;
        });
    }

    public function failures(string $name): int
    {
        return (int) $this->attempt(
            fn (Client $client): int => (int) $client->get(self::PREFIX.'failures:'.$name),
            fn (): int => $this->fallback->failures($name),
        );
    }

    /**
     * A live reading for the health page. It ignores the pause, so an administrator sees the real state.
     *
     * @return array{latency_ms: float, version: string, memory: string}|null Null when the server cannot be reached.
     */
    public function status(): ?array
    {
        try {
            $client = $this->client();
            $start = hrtime(true);
            $client->ping();
            $latency = (hrtime(true) - $start) / 1_000_000;
            $info = $client->info();

            return ['latency_ms' => round($latency, 2), 'version' => (string) $this->find($info, 'redis_version'), 'memory' => (string) $this->find($info, 'used_memory_human')];
        } catch (Throwable) {
            return null;
        }
    }

    public function fallbacks(): int
    {
        return (int) Cache::get('redis-module:fallbacks', 0);
    }

    /**
     * @template T
     *
     * @param  callable(Client): T  $redis
     * @param  callable(): T  $fallback
     * @return T
     */
    private function attempt(callable $redis, callable $fallback): mixed
    {
        if ($this->paused()) {
            return $this->fellBack($fallback);
        }
        try {
            return $redis($this->client());
        } catch (Throwable) {
            $this->pause();

            return $this->fellBack($fallback);
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $fallback
     * @return T
     */
    private function fellBack(callable $fallback): mixed
    {
        Cache::add('redis-module:fallbacks', 0, now()->addDays(7));
        Cache::increment('redis-module:fallbacks');

        return $fallback();
    }

    private function client(): Client
    {
        $parameters = [
            'scheme' => 'tcp',
            'host' => (string) ($this->connection['host'] ?? '127.0.0.1'),
            'port' => (int) ($this->connection['port'] ?? 6379),
            'database' => (int) ($this->connection['database'] ?? 0),
            'timeout' => 0.2,
            'read_write_timeout' => 0.2,
        ];
        foreach (['username', 'password'] as $credential) {
            if (($this->connection[$credential] ?? null) !== null && $this->connection[$credential] !== '') {
                $parameters[$credential] = (string) $this->connection[$credential];
            }
        }

        return $this->client ??= new Client($parameters);
    }

    /**
     * The pause is a file, not a cache entry: checking it costs a single stat call on the healthy path.
     */
    public static function pauseFile(): string
    {
        return storage_path('framework/redis-module-paused');
    }

    private function paused(): bool
    {
        $file = self::pauseFile();

        return is_file($file) && filemtime($file) > time() - self::PAUSE_SECONDS;
    }

    private function pause(): void
    {
        touch(self::pauseFile());
    }

    /**
     * @param  array<array-key, mixed>  $info
     */
    private function find(array $info, string $key): mixed
    {
        foreach ($info as $name => $value) {
            if ($name === $key) {
                return $value;
            }
            if (is_array($value) && ($found = $this->find($value, $key)) !== null) {
                return $found;
            }
        }

        return null;
    }
}
