<?php

namespace Tests\Feature;

use App\Installation\InstallationState;
use App\Models\ShareLink;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
