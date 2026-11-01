<?php

namespace Tests\Feature;

use App\Installation\InstallationState;
use App\Models\User;
use App\Models\Website;
use App\SiteFavicon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class SiteFaviconTest extends TestCase
{
    use RefreshDatabase;

    private string $png;

    protected function setUp(): void
    {
        parent::setUp();
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
        config(['cache.stores.file.path' => $this->installationDirectory.'/favicons', 'cache.stores.file.lock_path' => $this->installationDirectory.'/locks']);
        $this->png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
        Http::preventStrayRequests();
        $this->resolver(['93.184.216.34']);
    }

    private function resolver(array $addresses): void
    {
        $service = Mockery::mock(SiteFavicon::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('resolveAddresses')->andReturn($addresses);
        $this->app->instance(SiteFavicon::class, $service);
    }

    private function site(string $domain = 'example.com'): Website
    {
        $user = User::factory()->create();
        $site = Website::factory()->create(['domain' => $domain]);
        $site->members()->attach($user, ['role' => 'viewer']);
        $this->actingAs($user);

        return $site;
    }

    public function test_favicon_is_served_locally_and_cached_across_requests(): void
    {
        $site = $this->site();
        Http::fake(['https://example.com/favicon.ico' => function ($request, $options) {
            $this->assertSame(['example.com:443:93.184.216.34'], $options['curl'][CURLOPT_RESOLVE]);
            $this->assertFalse($options['decode_content']);

            return Http::response($this->png, 200, ['Content-Type' => 'image/png']);
        }]);
        $this->get('/sites/'.$site->id.'/favicon')->assertOk()->assertHeader('Content-Type', 'image/png')->assertContent($this->png)->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/sites/'.$site->id.'/favicon')->assertOk()->assertContent($this->png);
        Http::assertSentCount(1);
        $this->travel(8)->days();
        $this->get('/sites/'.$site->id.'/favicon')->assertOk();
        Http::assertSentCount(2);
    }

    public function test_missing_favicon_is_negatively_cached_then_retried(): void
    {
        $site = $this->site();
        Http::fake(['*' => Http::response('', 404)]);
        $this->get('/sites/'.$site->id.'/favicon')->assertRedirect('/site-icon.svg');
        $requests = Http::recorded()->count();
        $this->get('/sites/'.$site->id.'/favicon')->assertRedirect('/site-icon.svg');
        Http::assertSentCount($requests);
        $this->travel(13)->hours();
        $this->get('/sites/'.$site->id.'/favicon')->assertRedirect('/site-icon.svg');
        Http::assertSentCount($requests * 2);
    }

    public function test_missing_favicon_returns_no_content_when_the_interface_wants_initials(): void
    {
        $site = $this->site();
        Http::fake(['*' => Http::response('', 404)]);
        $this->get('/sites/'.$site->id.'/favicon?initials=1')->assertNoContent();
        $this->get('/sites/'.$site->id.'/favicon')->assertRedirect('/site-icon.svg');
    }

    public function test_declared_relative_icon_is_discovered_from_the_homepage(): void
    {
        $site = $this->site();
        Http::fake([
            'https://example.com/favicon.ico' => Http::response('', 404),
            'https://example.com/' => Http::response('<html><head><link rel="icon" href="assets/icon.png"></head></html>'),
            'https://example.com/assets/icon.png' => Http::response($this->png),
        ]);
        $this->get('/sites/'.$site->id.'/favicon')->assertOk()->assertContent($this->png);
        Http::assertSentCount(3);
    }

    public function test_non_members_cannot_fetch_icons_even_if_they_are_administrators(): void
    {
        $site = $this->site();
        Http::fake();
        $this->actingAs(User::factory()->create(['is_admin' => true]))->get('/sites/'.$site->id.'/favicon')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_private_addresses_and_mixed_dns_answers_are_never_requested(): void
    {
        $site = $this->site();
        Http::fake();
        foreach (['127.0.0.1', '10.0.0.1', '169.254.169.254', '100.64.0.1', '198.18.0.1', '::1', '::ffff:127.0.0.1'] as $address) {
            Cache::store('file')->flush();
            $this->resolver(['93.184.216.34', $address]);
            $this->get('/sites/'.$site->id.'/favicon')->assertRedirect('/site-icon.svg');
        }
        Http::assertNothingSent();
    }

    public function test_redirects_to_private_hosts_are_blocked(): void
    {
        $site = $this->site();
        Http::fake([
            'https://example.com/favicon.ico' => Http::response('', 302, ['Location' => 'https://127.0.0.1/private']),
            'https://example.com/' => Http::response('', 404),
        ]);
        $this->get('/sites/'.$site->id.'/favicon')->assertRedirect('/site-icon.svg');
        Http::assertSentCount(2);
    }

    public function test_invalid_or_oversized_images_fall_back_without_serving_remote_html(): void
    {
        $site = $this->site();
        foreach (['<script>alert(1)</script>', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', str_repeat('x', 262145)] as $body) {
            Cache::store('file')->flush();
            Http::fake(['*' => Http::response($body, 200, ['Content-Type' => 'image/png'])]);
            $this->get('/sites/'.$site->id.'/favicon')->assertRedirect('/site-icon.svg');
        }
    }

    public function test_network_failures_keep_the_default_icon_and_are_cached(): void
    {
        $site = $this->site();
        Http::fake(fn () => throw new ConnectionException('Connection timed out.'));
        $this->get('/sites/'.$site->id.'/favicon')->assertRedirect('/site-icon.svg');
        Http::fake(fn () => $this->fail('A failed download should not be retried before its cache expires.'));
        $this->get('/sites/'.$site->id.'/favicon')->assertRedirect('/site-icon.svg');
    }

    public function test_concurrent_download_uses_the_existing_lock_and_does_not_fetch_twice(): void
    {
        $site = $this->site();
        Http::fake(['*' => fn () => Http::response($this->png)]);
        $lock = Cache::store('file')->lock('site-favicon:v1:'.hash('sha256', $site->domain).':lock', 30);
        $this->assertTrue($lock->get());
        try {
            $this->get('/sites/'.$site->id.'/favicon')->assertRedirect('/site-icon.svg');
            Http::assertNothingSent();
        } finally {
            $lock->release();
        }
        $this->get('/sites/'.$site->id.'/favicon')->assertOk();
        Http::assertSentCount(1);
    }

    public function test_a_domain_change_uses_its_own_cache(): void
    {
        $site = $this->site();
        Http::fake(['*' => fn () => Http::response($this->png)]);
        $this->get('/sites/'.$site->id.'/favicon')->assertOk();
        $site->update(['domain' => 'other.example']);
        $this->get('/sites/'.$site->id.'/favicon')->assertOk();
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->url() === 'https://other.example/favicon.ico');
    }
}
