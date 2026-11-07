<?php

namespace Tests\Feature;

use App\Installation\InstallationState;
use App\Models\ShareLink;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ShareLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
    }

    private function siteWithOwner(): array
    {
        $site = Website::factory()->create();
        $owner = User::factory()->create();
        $site->members()->attach($owner, ['role' => 'owner']);

        return [$site, $owner];
    }

    /** @return array{0: Website, 1: ShareLink, 2: string} */
    private function link(array $sections = ['overview'], ?string $password = null, mixed $expires = null, ?Website $site = null): array
    {
        $site ??= Website::factory()->create(['name' => 'Public blog']);
        [$link, $token] = ShareLink::issue($site, null, 'Test', $sections, $password, $expires);

        return [$site, $link, $token];
    }

    public function test_valid_token_shows_overview_without_login(): void
    {
        [$site, , $token] = $this->link();
        DB::table('stats_daily')->insert(['website_id' => $site->id, 'stat_date' => now('UTC')->toDateString(), 'pageviews' => 42]);

        $this->get('/s/'.$token)->assertOk()->assertInertia(function (Assert $page) use ($site): void {
            $page->component('Share/Show')
                ->where('site', ['name' => 'Public blog', 'domain' => $site->domain, 'timezone' => 'UTC'])
                ->where('report.totals.pageviews', 42)
                ->where('sections', ['overview']);
            $keys = array_keys($page->toArray()['props']);
            sort($keys);
            $this->assertSame(['assets', 'currentUrl', 'dimension', 'dimensionOptions', 'dimensionReport', 'errors', 'flash', 'period', 'report', 'sections', 'series', 'site', 'today'], $keys);
        })->assertDontSee($site->public_id);
    }

    public function test_link_never_returns_another_site_or_unlisted_section(): void
    {
        $today = now('UTC')->toDateString();
        [$site, , $token] = $this->link(['pages']);
        $other = Website::factory()->create();
        DB::table('stats_daily')->insert([['website_id' => $site->id, 'stat_date' => $today, 'pageviews' => 5], ['website_id' => $other->id, 'stat_date' => $today, 'pageviews' => 900]]);
        DB::table('stats_dimension_daily')->insert([
            ['website_id' => $site->id, 'stat_date' => $today, 'dimension_type' => 'path', 'dimension_value' => '/mine', 'dimension_hash' => sha1('/mine'), 'metric_count' => 5],
            ['website_id' => $other->id, 'stat_date' => $today, 'dimension_type' => 'path', 'dimension_value' => '/theirs', 'dimension_hash' => sha1('/theirs'), 'metric_count' => 900],
            ['website_id' => $site->id, 'stat_date' => $today, 'dimension_type' => 'source', 'dimension_value' => 'secret-source', 'dimension_hash' => sha1('secret-source'), 'metric_count' => 5],
        ]);

        $this->get('/s/'.$token.'?website_id='.$other->id.'&site='.$other->id.'&filters[path][]=/theirs')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('report', null)->where('series', [])->where('dimension', 'path')->where('dimensionReport.total', 5)->where('dimensionReport.data.0.value', '/mine')->has('dimensionReport.data', 1));
        $this->get('/s/'.$token.'?dimension=source')->assertNotFound();
        $this->getJson('/s/'.$token.'/api/overview')->assertNotFound();
        $this->getJson('/s/'.$token.'/api/dimensions?dimension=source')->assertNotFound();
        $this->getJson('/s/'.$token.'/api/recent')->assertNotFound();
        $this->getJson('/s/'.$token.'/api/dimensions?dimension=path&website_id='.$other->id)->assertOk()->assertJsonPath('data.0.value', '/mine')->assertJsonMissingPath('data.1')->assertHeader('Cache-Control');
    }

    public function test_expired_and_revoked_links_return_identical_404(): void
    {
        [, , $expired] = $this->link(['overview'], null, now()->subMinute());
        [, $revoked, $revokedToken] = $this->link();
        $revoked->update(['revoked_at' => now()]);
        [$deleting, , $deletingToken] = $this->link();
        $deleting->forceFill(['deleting_at' => now()])->save();
        [, , $good] = $this->link();

        $this->get('/s/'.$good)->assertOk();
        $unknown = $this->get('/s/'.str_repeat('a', 43));
        $unknown->assertNotFound();
        $unknownJson = $this->getJson('/s/'.str_repeat('a', 43).'/api/overview');
        $unknownJson->assertNotFound();
        foreach ([$expired, $revokedToken, $deletingToken] as $token) {
            $page = $this->get('/s/'.$token);
            $page->assertNotFound();
            $this->assertSame($unknown->getContent(), $page->getContent());
            $json = $this->getJson('/s/'.$token.'/api/overview');
            $json->assertNotFound();
            $this->assertSame($unknownJson->getContent(), $json->getContent());
            $this->post('/s/'.$token.'/unlock', ['password' => 'x'])->assertNotFound();
        }
        $this->get('/s/'.str_repeat('a', 500))->assertNotFound();
    }

    public function test_password_required_then_session_unlocks(): void
    {
        [$site, , $token] = $this->link(['overview'], 'correct horse');
        DB::table('stats_daily')->insert(['website_id' => $site->id, 'stat_date' => now('UTC')->toDateString(), 'pageviews' => 7]);

        $this->get('/s/'.$token)->assertOk()->assertInertia(function (Assert $page): void {
            $page->component('Share/Unlock');
            $keys = array_keys($page->toArray()['props']);
            sort($keys);
            $this->assertSame(['assets', 'currentUrl', 'errors', 'flash'], $keys);
        })->assertDontSee('Public blog');
        $this->getJson('/s/'.$token.'/api/overview')->assertNotFound();

        $this->post('/s/'.$token.'/unlock', ['password' => 'wrong'])->assertSessionHasErrors('password');
        $this->get('/s/'.$token)->assertInertia(fn (Assert $page) => $page->component('Share/Unlock'));

        $this->post('/s/'.$token.'/unlock', ['password' => 'correct horse'])->assertRedirect('/s/'.$token);
        $this->get('/s/'.$token)->assertInertia(fn (Assert $page) => $page->component('Share/Show')->where('report.totals.pageviews', 7));
        $this->getJson('/s/'.$token.'/api/overview')->assertOk();
    }

    public function test_changing_password_invalidates_session(): void
    {
        [$site, $link, $token] = $this->link(['overview'], 'correct horse');
        $owner = User::factory()->create();
        $site->members()->attach($owner, ['role' => 'owner']);
        $this->post('/s/'.$token.'/unlock', ['password' => 'correct horse']);
        $this->get('/s/'.$token)->assertInertia(fn (Assert $page) => $page->component('Share/Show'));

        $this->actingAs($owner)->put('/sites/'.$site->id.'/sharing/'.$link->id, ['password' => 'another secret'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(2, $link->fresh()->secret_version);
        $this->assertNotSame('another secret', $link->fresh()->password_hash);
        $this->get('/s/'.$token)->assertInertia(fn (Assert $page) => $page->component('Share/Unlock'));
        $this->post('/s/'.$token.'/unlock', ['password' => 'correct horse'])->assertSessionHasErrors('password');
        $this->post('/s/'.$token.'/unlock', ['password' => 'another secret'])->assertRedirect();
        $this->get('/s/'.$token)->assertInertia(fn (Assert $page) => $page->component('Share/Show'));

        $this->put('/sites/'.$site->id.'/sharing/'.$link->id, ['remove_password' => true])->assertRedirect();
        $this->assertNull($link->fresh()->password_hash);
        $this->assertSame(3, $link->fresh()->secret_version);
    }

    public function test_sixth_wrong_password_is_throttled_per_link_and_per_ip(): void
    {
        [, , $token] = $this->link(['overview'], 'correct horse');
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post('/s/'.$token.'/unlock', ['password' => 'wrong '.$attempt])->assertSessionHasErrors(['password' => 'The password is not correct.']);
        }
        // The correct password no longer helps once the link is locked out.
        $this->post('/s/'.$token.'/unlock', ['password' => 'correct horse'])->assertSessionHasErrors(['password' => 'Too many attempts. Try again later.']);
        $this->get('/s/'.$token)->assertInertia(fn (Assert $page) => $page->component('Share/Unlock'));

        // Per IP: 20 attempts an hour across links, whatever the links.
        Cache::flush();
        $links = [];
        for ($index = 0; $index < 22; $index++) {
            $links[] = $this->link(['overview'], 'secret-'.$index)[2];
        }
        for ($index = 0; $index < 20; $index++) {
            $this->post('/s/'.$links[$index].'/unlock', ['password' => 'nope'])->assertSessionHasErrors(['password' => 'The password is not correct.']);
        }
        $this->post('/s/'.$links[20].'/unlock', ['password' => 'secret-20'])->assertSessionHasErrors(['password' => 'Too many attempts. Try again later.']);
    }

    public function test_throttle_uses_the_forwarded_address_only_from_a_trusted_proxy(): void
    {
        $tokens = [];
        for ($index = 0; $index < 22; $index++) {
            $tokens[] = $this->link(['overview'], 'secret-'.$index)[2];
        }
        // Untrusted proxy: the header is ignored, so changing it does not escape the limit.
        config(['perysee.trusted_proxies' => []]);
        for ($index = 0; $index < 20; $index++) {
            $this->withHeaders(['X-Forwarded-For' => '10.0.0.'.$index])->post('/s/'.$tokens[$index].'/unlock', ['password' => 'nope']);
        }
        $this->withHeaders(['X-Forwarded-For' => '203.0.113.99'])->post('/s/'.$tokens[20].'/unlock', ['password' => 'secret-20'])->assertSessionHasErrors(['password' => 'Too many attempts. Try again later.']);

        // Trusted proxy: each forwarded client has its own allowance.
        Cache::flush();
        config(['perysee.trusted_proxies' => ['127.0.0.1']]);
        for ($index = 0; $index < 20; $index++) {
            $this->withHeaders(['X-Forwarded-For' => '198.51.100.7'])->post('/s/'.$tokens[$index].'/unlock', ['password' => 'nope']);
        }
        $this->withHeaders(['X-Forwarded-For' => '198.51.100.7'])->post('/s/'.$tokens[20].'/unlock', ['password' => 'secret-20'])->assertSessionHasErrors(['password' => 'Too many attempts. Try again later.']);
        $this->withHeaders(['X-Forwarded-For' => '198.51.100.8'])->post('/s/'.$tokens[21].'/unlock', ['password' => 'secret-21'])->assertSessionHasNoErrors()->assertRedirect('/s/'.$tokens[21]);
    }

    public function test_only_an_owner_manages_links_and_never_those_of_another_site(): void
    {
        [$site, $owner] = $this->siteWithOwner();
        $viewer = User::factory()->create();
        $site->members()->attach($viewer, ['role' => 'viewer']);
        $admin = User::factory()->create(['is_admin' => true]);
        $payload = ['sections' => ['overview']];
        $this->actingAs($viewer)->get('/sites/'.$site->id.'/sharing')->assertForbidden();
        $this->post('/sites/'.$site->id.'/sharing', $payload)->assertForbidden();
        $this->actingAs($admin)->post('/sites/'.$site->id.'/sharing', $payload)->assertForbidden();
        $this->assertDatabaseCount('share_links', 0);

        [, $foreign] = $this->link();
        [, $mine] = $this->link(['overview'], null, null, $site);
        $this->actingAs($viewer)->delete('/sites/'.$site->id.'/sharing/'.$mine->id)->assertForbidden();
        $this->actingAs($owner)->delete('/sites/'.$site->id.'/sharing/'.$foreign->id)->assertNotFound();
        $this->assertNull($foreign->fresh()->revoked_at);
        $this->delete('/sites/'.$site->id.'/sharing/'.$mine->id)->assertRedirect();
        $this->assertNotNull($mine->fresh()->revoked_at);
        $this->post('/sites/'.$site->id.'/sharing', ['sections' => ['nonsense']])->assertSessionHasErrors('sections.0');
        $this->post('/sites/'.$site->id.'/sharing', ['sections' => []])->assertSessionHasErrors('sections');
    }

    public function test_owner_creates_link_and_only_hash_is_stored(): void
    {
        [$site, $owner] = $this->siteWithOwner();
        $this->actingAs($owner)->post('/sites/'.$site->id.'/sharing', ['label' => 'Board report', 'sections' => ['overview', 'pages']])->assertSessionHasNoErrors()->assertRedirect('/sites/'.$site->id.'/sharing');

        $link = ShareLink::firstOrFail();
        $this->assertSame($site->id, $link->website_id);
        $this->assertSame($owner->id, $link->created_by);
        $this->assertSame(['overview', 'pages'], $link->sections);
        $this->assertSame(64, strlen($link->token_hash));

        $token = null;
        $this->get('/sites/'.$site->id.'/sharing')->assertOk()->assertInertia(function (Assert $page) use (&$token): void {
            $page->where('screen', 'sites.sharing')->has('shareLinks', 1)->missing('shareLinks.0.token_hash')->missing('shareLinks.0.password_hash');
            $url = $page->toArray()['props']['created']['url'];
            $this->assertMatchesRegularExpression('#^/s/[A-Za-z0-9_-]{43}$#', $url);
            $token = substr($url, 3);
        });
        $this->assertSame(hash('sha256', $token), $link->token_hash);
        $this->assertSame(substr($token, 0, 6), $link->token_hint);
        $this->assertSame(0, DB::table('share_links')->where('token_hash', $token)->count());
        // The raw token is shown once.
        $this->get('/sites/'.$site->id.'/sharing')->assertInertia(fn (Assert $page) => $page->where('created', null));
    }
}
