<?php

namespace Tests\Feature;

use App\Installation\InstallationState;
use App\Models\ApiToken;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ApiV1RateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
    }

    private function auth(int $rate, ?Website $site = null): array
    {
        [, $secret] = ApiToken::issue($site ?? Website::factory()->create(), null, 'Test', ['overview'], $rate, null);

        return ['Authorization' => 'Bearer '.$secret];
    }

    public function test_the_call_after_the_limit_is_a_429_with_retry_after(): void
    {
        $this->travelTo(now()->startOfMinute()->addSeconds(5));
        $auth = $this->auth(10);
        for ($i = 1; $i <= 10; $i++) {
            $response = $this->getJson('/api/v1/site', $auth)->assertOk();
            $this->assertSame('10', $response->headers->get('X-RateLimit-Limit'));
            $this->assertSame((string) (10 - $i), $response->headers->get('X-RateLimit-Remaining'));
        }
        $limited = $this->getJson('/api/v1/site', $auth);
        $limited->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
        $this->assertSame('0', $limited->headers->get('X-RateLimit-Remaining'));
        $retry = (int) $limited->headers->get('Retry-After');
        $this->assertGreaterThanOrEqual(1, $retry);
        $this->assertLessThanOrEqual(60, $retry);
        $this->assertSame(now()->timestamp + $retry, (int) $limited->headers->get('X-RateLimit-Reset'));
        $this->assertSame('1', $limited->headers->get('X-Perysee-Api-Version'));

        $this->travel($retry + 1)->seconds();
        $this->getJson('/api/v1/site', $auth)->assertOk();
    }

    public function test_tokens_do_not_share_a_counter(): void
    {
        $site = Website::factory()->create();
        $first = $this->auth(10, $site);
        $second = $this->auth(10, $site);
        for ($i = 0; $i < 11; $i++) {
            $this->getJson('/api/v1/site', $first);
        }
        $this->getJson('/api/v1/site', $first)->assertStatus(429);
        $this->getJson('/api/v1/site', $second)->assertOk();
    }

    public function test_the_limit_is_the_one_chosen_for_the_token(): void
    {
        $response = $this->getJson('/api/v1/site', $this->auth(250));
        $this->assertSame('250', $response->headers->get('X-RateLimit-Limit'));
        $this->assertSame('249', $response->headers->get('X-RateLimit-Remaining'));
    }
}
