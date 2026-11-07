<?php

namespace Tests\Feature;

use App\Installation\InstallationState;
use App\Models\ApiToken;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ApiV1AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
    }

    /** @return array{0: Website, 1: ApiToken, 2: string} */
    private function token(array $scopes = ['overview'], mixed $expires = null, ?Website $site = null, int $rate = 60): array
    {
        $site ??= Website::factory()->create(['name' => 'Public blog']);
        [$token, $secret] = ApiToken::issue($site, null, 'Test', $scopes, $rate, $expires);

        return [$site, $token, $secret];
    }

    private function auth(string $secret): array
    {
        return ['Authorization' => 'Bearer '.$secret];
    }

    public function test_a_valid_token_reads_its_site(): void
    {
        [$site, , $secret] = $this->token(['overview', 'pages']);
        $response = $this->getJson('/api/v1/site', $this->auth($secret))->assertOk();

        $this->assertSame([
            'data' => [
                'site' => ['name' => 'Public blog', 'domain' => $site->domain, 'timezone' => 'UTC'],
                'token' => ['label' => 'Test', 'scopes' => ['overview', 'pages'], 'rate_per_minute' => 60, 'expires_at' => null],
            ],
            'meta' => ['api_version' => 1],
        ], $response->json());
        $this->assertStringNotContainsString($site->public_id, $response->getContent());
    }

    public function test_every_unusable_token_gets_the_same_401(): void
    {
        [, , $expired] = $this->token(['overview'], now()->subMinute());
        [, $revokedToken, $revoked] = $this->token();
        $revokedToken->update(['revoked_at' => now()]);
        [$deleting, , $deletingSecret] = $this->token();
        $deleting->forceFill(['deleting_at' => now()])->save();
        [$demo, , $demoSecret] = $this->token();
        $demo->forceFill(['is_demo' => true])->save();
        [, , $good] = $this->token();

        $this->getJson('/api/v1/site', $this->auth($good))->assertOk();
        $reference = $this->getJson('/api/v1/site', $this->auth('psy_'.str_repeat('a', 43)));
        $reference->assertUnauthorized()->assertHeader('WWW-Authenticate', 'Bearer')->assertJsonPath('error.code', 'invalid_token');
        $this->assertSame(['error' => ['code' => 'invalid_token', 'message' => 'The API token is missing, malformed, expired or revoked.', 'details' => []]], $reference->json());

        $cases = [
            'no header' => [],
            'empty bearer' => ['Authorization' => 'Bearer '],
            'basic scheme' => ['Authorization' => 'Basic '.base64_encode('a:b')],
            'garbage' => $this->auth('not a token'),
            'too long' => $this->auth('psy_'.str_repeat('a', 500)),
            'expired' => $this->auth($expired),
            'revoked' => $this->auth($revoked),
            'site being deleted' => $this->auth($deletingSecret),
            'demonstration site' => $this->auth($demoSecret),
            'share-like token' => $this->auth(str_repeat('a', 43)),
        ];
        foreach ($cases as $name => $headers) {
            $response = $this->getJson('/api/v1/site', $headers);
            $response->assertUnauthorized();
            $this->assertSame($reference->getContent(), $response->getContent(), $name);
            $this->assertSame('Bearer', $response->headers->get('WWW-Authenticate'), $name);
        }
    }

    public function test_the_secret_is_never_read_from_the_query_string_or_the_body(): void
    {
        [, , $secret] = $this->token();
        $this->getJson('/api/v1/site?token='.$secret)->assertUnauthorized();
        $this->getJson('/api/v1/site?access_token='.$secret.'&api_token='.$secret)->assertUnauthorized();
        $this->call('GET', '/api/v1/site', ['token' => $secret], [], [], ['HTTP_ACCEPT' => 'application/json'])->assertUnauthorized();
    }

    public function test_responses_carry_the_security_headers_and_no_cookie(): void
    {
        [, , $secret] = $this->token();
        $responses = [
            $this->getJson('/api/v1/site', $this->auth($secret)),
            $this->getJson('/api/v1/site'),
            $this->getJson('/api/v1/nothing-here', $this->auth($secret)),
            $this->postJson('/api/v1/site', [], $this->auth($secret)),
        ];
        foreach ($responses as $response) {
            $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
            $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
            $this->assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
            $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
            $this->assertSame('1', $response->headers->get('X-Perysee-Api-Version'));
            $this->assertStringContainsString('application/json', $response->headers->get('Content-Type'));
            $this->assertSame([], $response->headers->getCookies());
        }
    }

    public function test_errors_have_one_shape_for_unknown_routes_and_methods(): void
    {
        [, , $secret] = $this->token();
        $this->getJson('/api/v1/nothing-here', $this->auth($secret))->assertNotFound()->assertJsonPath('error.code', 'not_found');
        foreach (['post', 'put', 'patch', 'delete'] as $method) {
            $this->{$method.'Json'}('/api/v1/site', [], $this->auth($secret))->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
        }
        $this->getJson('/api/v1/site', $this->auth($secret))->assertOk();
        $this->call('HEAD', '/api/v1/site', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$secret])->assertOk();
    }

    public function test_no_cross_origin_access_is_ever_granted(): void
    {
        [, , $secret] = $this->token();
        $origin = ['Origin' => 'https://evil.example'];
        $response = $this->getJson('/api/v1/site', $this->auth($secret) + $origin);
        $response->assertOk();
        $preflight = $this->call('OPTIONS', '/api/v1/site', [], [], [], ['HTTP_ORIGIN' => 'https://evil.example', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET', 'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization']);
        foreach ([$response, $preflight, $this->getJson('/api/v1/site', $origin)] as $each) {
            foreach (array_keys($each->headers->all()) as $name) {
                $this->assertStringStartsNotWith('access-control-', $name);
            }
        }
        $this->assertSame(405, $preflight->getStatusCode());
        // The collector keeps the cross-origin behaviour it has always had.
        $collect = $this->call('OPTIONS', '/api/collect', [], [], [], ['HTTP_ORIGIN' => 'https://blog.example', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST']);
        $this->assertNotNull($collect->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_last_used_at_is_stamped_once_per_window(): void
    {
        $this->travelTo(now()->startOfMinute());
        [, $token, $secret] = $this->token();
        $this->assertNull($token->last_used_at);
        $this->getJson('/api/v1/site', $this->auth($secret))->assertOk();
        $first = $token->fresh()->last_used_at;
        $this->assertNotNull($first);
        $this->assertSame(1, $token->fresh()->use_count);

        $this->travel(9)->minutes();
        $this->getJson('/api/v1/site', $this->auth($secret))->assertOk();
        $this->assertEquals($first, $token->fresh()->last_used_at);
        $this->assertSame(1, $token->fresh()->use_count);

        $this->travel(2)->minutes();
        $this->getJson('/api/v1/site', $this->auth($secret))->assertOk();
        $this->assertTrue($token->fresh()->last_used_at->gt($first));
        $this->assertSame(2, $token->fresh()->use_count);

        // A refused request is not a use.
        [, $other] = $this->token();
        $this->getJson('/api/v1/site', $this->auth('psy_'.str_repeat('b', 43)))->assertUnauthorized();
        $this->assertNull($other->fresh()->last_used_at);
    }

    public function test_a_token_never_reaches_the_web_interface_or_another_site(): void
    {
        [$site, , $secret] = $this->token();
        $other = Website::factory()->create();
        $owner = User::factory()->create();
        $site->members()->attach($owner, ['role' => 'owner']);
        $this->getJson('/api/sites/'.$site->id.'/overview', $this->auth($secret))->assertUnauthorized();
        $this->getJson('/api/v1/site?website_id='.$other->id.'&site='.$other->id, $this->auth($secret))->assertOk()->assertJsonPath('data.site.domain', $site->domain);
        $this->getJson('/api/v1/sites/'.$other->id, $this->auth($secret))->assertNotFound();
        $this->get('/sites/'.$site->id.'/api-tokens', $this->auth($secret))->assertRedirect('/login');
    }

    public function test_misses_from_one_address_are_limited_without_affecting_a_good_token(): void
    {
        Cache::flush();
        [, , $secret] = $this->token();
        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/api/v1/site', $this->auth('psy_'.str_repeat('c', 43)))->assertUnauthorized();
        }
        $limited = $this->getJson('/api/v1/site', $this->auth('psy_'.str_repeat('c', 43)));
        $limited->assertStatus(429)->assertJsonPath('error.code', 'rate_limited')->assertHeader('Retry-After', '60');
        // A client with a good token is not limited by the misses of its address.
        $this->getJson('/api/v1/site', $this->auth($secret))->assertOk();
        // Another address has its own allowance.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->getJson('/api/v1/site', $this->auth('psy_'.str_repeat('c', 43)))->assertUnauthorized();
    }
}
