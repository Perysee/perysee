<?php

namespace Perysee\Modules\Redis;

use App\Modules\ModuleExtensions;
use App\Modules\ModuleProvider;
use App\Support\Coordination;

class ModuleServiceProvider extends ModuleProvider
{
    public function register(): void
    {
        // A Core without the Coordination service has nothing to hand over: the module then stays inert.
        if (! interface_exists(Coordination::class) || ! config('perysee.redis_enabled')) {
            return;
        }
        $this->app->singleton(Coordination::class, fn (): Coordination => new RedisCoordination((array) config('database.redis.default')));
    }

    public function boot(): void
    {
        if (method_exists(ModuleExtensions::class, 'healthCard')) {
            $this->healthCard(fn (): array => $this->card());
        }
    }

    /**
     * @return array{title: string, status: string, items: list<array{label: string, value: string|int|float}>}
     */
    private function card(): array
    {
        if (! config('perysee.redis_enabled')) {
            return ['title' => 'Redis', 'status' => 'attention', 'items' => [
                ['label' => 'Connection', 'value' => 'Not switched on'],
                ['label' => 'To switch on', 'value' => 'Set PERYSEE_REDIS_ENABLED=true, REDIS_HOST, REDIS_PORT and REDIS_PASSWORD'],
            ]];
        }
        $coordination = $this->app->make(Coordination::class);
        $status = $coordination instanceof RedisCoordination ? $coordination->status() : null;
        if ($status === null) {
            return ['title' => 'Redis', 'status' => 'attention', 'items' => [
                ['label' => 'Connection', 'value' => 'Unreachable'],
                ['label' => 'Behaviour', 'value' => 'Collection continues on the application cache'],
                ['label' => 'Fallbacks', 'value' => $coordination instanceof RedisCoordination ? $coordination->fallbacks() : 0],
            ]];
        }

        return ['title' => 'Redis', 'status' => 'ok', 'items' => [
            ['label' => 'Connection', 'value' => 'Connected'],
            ['label' => 'Version', 'value' => $status['version']],
            ['label' => 'Latency', 'value' => $status['latency_ms'].' ms'],
            ['label' => 'Memory', 'value' => $status['memory']],
            ['label' => 'Fallbacks', 'value' => $coordination->fallbacks()],
        ]];
    }
}
