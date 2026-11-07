<?php

namespace Tests\Feature;

use App\Installation\InstallationState;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WebsiteCurrencyTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Website $site;

    protected function setUp(): void
    {
        parent::setUp();
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
        $this->owner = User::factory()->create();
        $this->site = Website::factory()->create();
        $this->site->members()->attach($this->owner, ['role' => 'owner']);
        $this->actingAs($this->owner);
    }

    private function payload(array $extra = []): array
    {
        return array_merge(['name' => $this->site->name, 'domain' => $this->site->domain, 'allowed_domains' => $this->site->domain, 'timezone' => $this->site->timezone], $extra);
    }

    public function test_a_new_site_has_no_currency(): void
    {
        $this->assertNull($this->site->fresh()->currency);
    }

    public function test_the_owner_sets_an_iso_currency_in_any_case(): void
    {
        $this->put('/sites/'.$this->site->id, $this->payload(['currency' => 'eur']))->assertSessionHasNoErrors();

        $this->assertSame('EUR', $this->site->fresh()->currency);
    }

    public function test_an_unknown_currency_is_refused(): void
    {
        foreach (['EURO', 'E', 'XXX', '12A', 'ZZZ', ['EUR']] as $bad) {
            $this->put('/sites/'.$this->site->id, $this->payload(['currency' => $bad]))->assertSessionHasErrors('currency');
        }
        $this->assertNull($this->site->fresh()->currency);
    }

    public function test_an_empty_currency_clears_it_and_an_absent_one_keeps_it(): void
    {
        $this->site->update(['currency' => 'USD']);

        $this->put('/sites/'.$this->site->id, $this->payload())->assertSessionHasNoErrors();
        $this->assertSame('USD', $this->site->fresh()->currency);

        $this->put('/sites/'.$this->site->id, $this->payload(['currency' => '']))->assertSessionHasNoErrors();
        $this->assertNull($this->site->fresh()->currency);
    }

    public function test_the_form_receives_the_currency_and_the_list(): void
    {
        $this->site->update(['currency' => 'GBP']);

        $this->get('/sites/'.$this->site->id.'/edit')->assertOk()->assertInertia(fn (Assert $page) => $page->where('website.currency', 'GBP')->has('currencies', fn (Assert $list) => $list->etc()));
        $this->get('/sites/create')->assertOk()->assertInertia(fn (Assert $page) => $page->has('currencies'));
    }

    public function test_a_viewer_cannot_change_it(): void
    {
        $viewer = User::factory()->create();
        $this->site->members()->attach($viewer, ['role' => 'viewer']);

        $this->actingAs($viewer)->put('/sites/'.$this->site->id, $this->payload(['currency' => 'EUR']))->assertForbidden();
        $this->assertNull($this->site->fresh()->currency);
    }

    public function test_creating_a_site_with_a_currency(): void
    {
        $this->post('/sites', $this->payload(['name' => 'Shop', 'domain' => 'shop.example.com', 'allowed_domains' => '', 'currency' => 'CHF']))->assertSessionHasNoErrors();

        $this->assertSame('CHF', Website::where('domain', 'shop.example.com')->value('currency'));
    }
}
