<?php

namespace Tests\Feature;

use App\Installation\InstallationState;
use App\Models\ApiToken;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ApiTokenManagementTest extends TestCase
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

    public function test_owner_creates_a_token_and_only_its_hash_is_stored(): void
    {
        [$site, $owner] = $this->siteWithOwner();
        $this->actingAs($owner)->post('/sites/'.$site->id.'/api-tokens', ['label' => 'WordPress', 'scopes' => ['overview', 'pages'], 'rate_per_minute' => 120])
            ->assertSessionHasNoErrors()->assertRedirect('/sites/'.$site->id.'/api-tokens');

        $row = ApiToken::firstOrFail();
        $this->assertSame($site->id, $row->website_id);
        $this->assertSame($owner->id, $row->created_by);
        $this->assertSame(['overview', 'pages'], $row->scopes);
        $this->assertSame(120, $row->rate_per_minute);
        $this->assertSame(64, strlen($row->token_hash));

        $secret = null;
        $response = $this->get('/sites/'.$site->id.'/api-tokens')->assertOk();
        $response->assertInertia(function (Assert $page) use (&$secret): void {
            $page->where('screen', 'sites.api-tokens')->has('apiTokens', 1)->missing('apiTokens.0.token_hash')->missing('apiTokens.0.token');
            $secret = $page->toArray()['props']['created']['token'];
        });
        $this->assertMatchesRegularExpression('/^psy_[A-Za-z0-9_-]{43}$/', $secret);
        $this->assertSame(hash('sha256', $secret), $row->token_hash);
        $this->assertSame(substr($secret, -6), $row->token_hint);
        $this->assertSame(0, DB::table('api_tokens')->where('token_hash', $secret)->count());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        // The secret is shown once, and the listing never carries it.
        $again = $this->get('/sites/'.$site->id.'/api-tokens');
        $again->assertInertia(fn (Assert $page) => $page->where('created', null));
        $this->assertStringNotContainsString($secret, $again->getContent());
    }

    public function test_default_rate_and_optional_expiry(): void
    {
        [$site, $owner] = $this->siteWithOwner();
        $this->actingAs($owner)->post('/sites/'.$site->id.'/api-tokens', ['scopes' => ['overview'], 'expires_on' => now('UTC')->addDays(10)->toDateString()])->assertSessionHasNoErrors();
        $row = ApiToken::firstOrFail();
        $this->assertSame(60, $row->rate_per_minute);
        $this->assertSame('', $row->label);
        $this->assertTrue($row->expires_at->isFuture());
        $this->assertSame('active', $row->status());
    }

    public function test_validation(): void
    {
        [$site, $owner] = $this->siteWithOwner();
        $this->actingAs($owner);
        $url = '/sites/'.$site->id.'/api-tokens';
        $this->post($url, [])->assertSessionHasErrors('scopes');
        $this->post($url, ['scopes' => []])->assertSessionHasErrors('scopes');
        $this->post($url, ['scopes' => ['nonsense']])->assertSessionHasErrors('scopes.0');
        $this->post($url, ['scopes' => ['overview', 'overview']])->assertSessionHasErrors('scopes.0');
        $this->post($url, ['scopes' => ['overview'], 'rate_per_minute' => 9])->assertSessionHasErrors('rate_per_minute');
        $this->post($url, ['scopes' => ['overview'], 'rate_per_minute' => 301])->assertSessionHasErrors('rate_per_minute');
        $this->post($url, ['scopes' => ['overview'], 'expires_on' => now('UTC')->subDays(2)->toDateString()])->assertSessionHasErrors('expires_on');
        $this->post($url, ['scopes' => ['overview'], 'label' => str_repeat('a', 121)])->assertSessionHasErrors('label');
        $this->assertDatabaseCount('api_tokens', 0);
        config(['perysee.api.max_rate' => 100]);
        $this->post($url, ['scopes' => ['overview'], 'rate_per_minute' => 101])->assertSessionHasErrors('rate_per_minute');
        $this->post($url, ['scopes' => ['overview'], 'rate_per_minute' => 100])->assertSessionHasNoErrors();
        config(['perysee.api.max_expiry_days' => 30]);
        $this->post($url, ['scopes' => ['overview'], 'expires_on' => now('UTC')->addDays(40)->toDateString()])->assertSessionHasErrors('expires_on');
    }

    public function test_every_scope_is_accepted(): void
    {
        [$site, $owner] = $this->siteWithOwner();
        $all = ['overview', 'pages', 'sources', 'campaigns', 'devices', 'countries', 'events', 'goals', 'funnels'];
        $this->actingAs($owner)->get('/sites/'.$site->id.'/api-tokens')->assertInertia(fn (Assert $page) => $page->where('scopeOptions', $all));
        $this->post('/sites/'.$site->id.'/api-tokens', ['scopes' => $all])->assertSessionHasNoErrors();
        $this->assertSame($all, ApiToken::firstOrFail()->scopes);
    }

    public function test_only_an_owner_manages_tokens(): void
    {
        [$site, $owner] = $this->siteWithOwner();
        $viewer = User::factory()->create();
        $site->members()->attach($viewer, ['role' => 'viewer']);
        $admin = User::factory()->create(['is_admin' => true]);
        $outsider = User::factory()->create();
        [$token] = ApiToken::issue($site, $owner, 'x', ['overview'], 60, null);

        foreach ([$viewer, $admin, $outsider] as $user) {
            $this->actingAs($user)->get('/sites/'.$site->id.'/api-tokens')->assertForbidden();
            $this->post('/sites/'.$site->id.'/api-tokens', ['scopes' => ['overview']])->assertForbidden();
            $this->delete('/sites/'.$site->id.'/api-tokens/'.$token->id)->assertForbidden();
        }
        $this->assertDatabaseCount('api_tokens', 1);
        $this->assertNull($token->fresh()->revoked_at);
        $this->actingAs($viewer)->get('/sites/'.$site->id)->assertInertia(fn (Assert $page) => $page->where('canManageApiTokens', false));
        $this->actingAs($owner)->get('/sites/'.$site->id)->assertInertia(fn (Assert $page) => $page->where('canManageApiTokens', true)->where('siteLinks.api-tokens', '/sites/'.$site->id.'/api-tokens'));
    }

    public function test_guests_are_sent_to_login(): void
    {
        [$site] = $this->siteWithOwner();
        $this->get('/sites/'.$site->id.'/api-tokens')->assertRedirect('/login');
    }

    public function test_demo_and_deleting_sites_are_not_found(): void
    {
        [$site, $owner] = $this->siteWithOwner();
        $this->actingAs($owner);
        $site->forceFill(['is_demo' => true])->save();
        $this->get('/sites/'.$site->id.'/api-tokens')->assertNotFound();
        $this->post('/sites/'.$site->id.'/api-tokens', ['scopes' => ['overview']])->assertNotFound();
        $site->forceFill(['is_demo' => false, 'deleting_at' => now()])->save();
        $this->get('/sites/'.$site->id.'/api-tokens')->assertNotFound();
        $this->assertDatabaseCount('api_tokens', 0);
    }

    public function test_revocation_is_idempotent_and_scoped_to_the_site(): void
    {
        [$site, $owner] = $this->siteWithOwner();
        $other = Website::factory()->create();
        [$mine] = ApiToken::issue($site, $owner, 'mine', ['overview'], 60, null);
        [$foreign] = ApiToken::issue($other, null, 'foreign', ['overview'], 60, null);
        $this->actingAs($owner);

        $this->delete('/sites/'.$site->id.'/api-tokens/'.$foreign->id)->assertNotFound();
        $this->assertNull($foreign->fresh()->revoked_at);
        $this->delete('/sites/'.$site->id.'/api-tokens/'.$mine->id)->assertRedirect('/sites/'.$site->id.'/api-tokens');
        $first = $mine->fresh()->revoked_at;
        $this->assertNotNull($first);
        $this->travel(5)->minutes();
        $this->delete('/sites/'.$site->id.'/api-tokens/'.$mine->id)->assertRedirect();
        $this->assertEquals($first, $mine->fresh()->revoked_at);
    }

    public function test_listing_shows_status_scopes_and_urls_without_secrets(): void
    {
        [$site, $owner] = $this->siteWithOwner();
        [$active] = ApiToken::issue($site, $owner, 'Active', ['overview', 'pages'], 90, null);
        [$expired] = ApiToken::issue($site, $owner, 'Expired', ['overview'], 60, now()->subDay());
        [$revoked] = ApiToken::issue($site, $owner, 'Revoked', ['goals'], 60, null);
        $revoked->update(['revoked_at' => now()]);

        $this->actingAs($owner)->get('/sites/'.$site->id.'/api-tokens')->assertInertia(fn (Assert $page) => $page
            ->component('Workspace')->where('screen', 'sites.api-tokens')->has('apiTokens', 3)
            ->where('apiTokens.0.status', 'revoked')->where('apiTokens.1.status', 'expired')->where('apiTokens.2.status', 'active')
            ->where('apiTokens.2.scopes', ['overview', 'pages'])->where('apiTokens.2.rate_per_minute', 90)
            ->where('apiTokens.2.revoke_url', '/sites/'.$site->id.'/api-tokens/'.$active->id)
            ->where('apiTokens.2.hint', $active->token_hint)
            ->where('rateLimits', ['default' => 60, 'min' => 10, 'max' => 300]));
        $this->assertNotNull($expired->id);
    }

    public function test_tokens_follow_the_site_when_it_is_deleted(): void
    {
        [$site, $owner] = $this->siteWithOwner();
        ApiToken::issue($site, $owner, 'x', ['overview'], 60, null);
        $site->delete();
        $this->assertDatabaseCount('api_tokens', 0);
    }

    public function test_a_token_survives_when_its_creator_is_removed(): void
    {
        [$site, $owner] = $this->siteWithOwner();
        [$token] = ApiToken::issue($site, $owner, 'x', ['overview'], 60, null);
        $owner->delete();
        $this->assertNull($token->fresh()->created_by);
        $this->assertSame('active', $token->fresh()->status());
    }
}
