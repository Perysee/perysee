<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class NamedThrottlesTest extends TestCase
{
    /** @return list<string> */
    private function throttles(): array
    {
        $found = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, 'throttle:')) {
                    $found[] = $middleware;
                }
            }
        }

        return $found;
    }

    public function test_every_throttle_middleware_names_its_limiter(): void
    {
        // An unnamed throttle:N,M keeps one counter per user, shared by every route that uses it.
        $unnamed = array_values(array_filter($this->throttles(), fn (string $middleware): bool => (bool) preg_match('/^throttle:\d/', $middleware)));

        $this->assertSame([], $unnamed);
    }

    public function test_every_named_throttle_is_defined(): void
    {
        $names = array_unique(array_map(fn (string $middleware): string => substr($middleware, 9), $this->throttles()));

        $this->assertNotEmpty($names);
        foreach ($names as $name) {
            $this->assertNotNull(RateLimiter::limiter($name), $name);
        }
    }
}
