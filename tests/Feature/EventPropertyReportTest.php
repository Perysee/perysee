<?php

namespace Tests\Feature;

use App\Analytics\Aggregation\Aggregator;
use App\Installation\InstallationState;
use App\Models\ShareLink;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EventPropertyReportTest extends TestCase
{
    use RefreshDatabase;

    private Website $site;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
        $this->travelTo(now('UTC')->setDate(2026, 10, 26)->setTime(12, 0));
        $this->site = Website::factory()->create();
        $this->owner = User::factory()->create();
        $this->site->members()->attach($this->owner, ['role' => 'owner']);
    }

    private function named(string $name, array $props, ?int $thousandths = null, string $day = '2026-10-24'): void
    {
        DB::table('events')->insert(['website_id' => $this->site->id, 'received_at' => $day.' 10:00:00', 'event_type' => 2, 'event_name' => $name, 'path' => '/', 'event_value' => $thousandths, 'event_props' => json_encode($props), 'consent_given' => true]);
    }

    private function api(string $query = ''): array
    {
        return $this->actingAs($this->owner)->getJson('/api/sites/'.$this->site->id.'/events/properties?from=2026-10-20&to=2026-10-26'.$query)->assertOk()->json();
    }

    public function test_events_are_listed_and_the_first_is_selected(): void
    {
        $this->named('purchase', ['payment' => 'card']);
        $this->named('purchase', ['payment' => 'card']);
        $this->named('signup', ['plan' => 'pro']);
        app(Aggregator::class)->run();

        $report = $this->api();

        $this->assertSame([['name' => 'purchase', 'keys' => 1, 'count' => 2], ['name' => 'signup', 'keys' => 1, 'count' => 1]], $report['events']);
        $this->assertSame('purchase', $report['event']);
        $this->assertSame('payment', $report['keys'][0]['key']);
    }

    public function test_values_are_counted_and_valued(): void
    {
        $this->named('purchase', ['payment' => 'card'], 10000);
        $this->named('purchase', ['payment' => 'card'], 20000, '2026-10-25');
        $this->named('purchase', ['payment' => 'paypal'], null);
        app(Aggregator::class)->run();

        $key = $this->api('&event=purchase')['keys'][0];

        $this->assertSame(3, $key['count']);
        $this->assertEquals(30, $key['value']);
        $card = $key['values'][0];
        $this->assertSame(['card', 2, 2], [$card['value'], $card['count'], $card['valued']]);
        $this->assertEquals([30, 15], [$card['amount'], $card['average']]);
        $paypal = $key['values'][1];
        $this->assertSame(['paypal', 1, 0, null], [$paypal['value'], $paypal['count'], $paypal['valued'], $paypal['average']]);
    }

    public function test_the_period_limits_the_figures(): void
    {
        $this->named('purchase', ['payment' => 'card'], null, '2026-10-24');
        $this->named('purchase', ['payment' => 'card'], null, '2026-10-10');
        app(Aggregator::class)->run();

        $this->assertSame(1, $this->api()['keys'][0]['count']);
        $this->assertSame(2, $this->api('&from=2026-10-01')['keys'][0]['count'] ?? 0);
    }

    public function test_at_most_fifty_values_are_listed_and_the_rest_is_summed_as_other_with_an_exact_total(): void
    {
        foreach (range(1, 40) as $i) {
            $this->named('purchase', ['sku' => 'a'.$i], 1000);
        }
        foreach (range(1, 40) as $i) {
            $this->named('purchase', ['sku' => 'b'.$i], 1000, '2026-10-25');
        }
        app(Aggregator::class)->run();

        $key = $this->api('&event=purchase')['keys'][0];

        $this->assertSame(80, $key['count']);
        $this->assertLessThanOrEqual(51, count($key['values']));
        $this->assertSame(80, array_sum(array_column($key['values'], 'count')));
        $this->assertSame('(other)', end($key['values'])['value']);
        $this->assertEquals(80, array_sum(array_column($key['values'], 'amount')));
    }

    public function test_a_value_is_not_split_by_letter_case_of_the_database(): void
    {
        $this->named('purchase', ['payment' => 'Card']);
        $this->named('purchase', ['payment' => 'card']);
        app(Aggregator::class)->run();

        $values = array_column($this->api('&event=purchase')['keys'][0]['values'], 'count', 'value');

        $this->assertSame(['Card' => 1, 'card' => 1], $values);
    }

    public function test_an_unknown_event_gives_an_empty_report(): void
    {
        $this->named('purchase', ['payment' => 'card']);
        app(Aggregator::class)->run();

        $this->assertSame([], $this->api('&event=refund')['keys']);
    }

    public function test_an_invalid_event_name_is_refused(): void
    {
        $this->actingAs($this->owner)->getJson('/api/sites/'.$this->site->id.'/events/properties?event='.rawurlencode('bad name!'))->assertUnprocessable();
        $this->actingAs($this->owner)->getJson('/api/sites/'.$this->site->id.'/events/properties?event=(other)')->assertOk();
    }

    public function test_no_session_or_visitor_is_in_the_payload(): void
    {
        $this->named('purchase', ['payment' => 'card'], 100);
        app(Aggregator::class)->run();

        $json = json_encode($this->api());

        foreach (['session', 'visitor', 'event_props', 'ip'] as $forbidden) {
            $this->assertStringNotContainsString('"'.$forbidden, $json);
        }
    }

    public function test_the_page_renders_for_a_viewer_and_is_closed_to_strangers(): void
    {
        $this->named('purchase', ['payment' => 'card']);
        app(Aggregator::class)->run();
        $viewer = User::factory()->create();
        $this->site->members()->attach($viewer, ['role' => 'viewer']);

        $this->actingAs($viewer)->get('/sites/'.$this->site->id.'/events/properties?from=2026-10-20&to=2026-10-26')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('screen', 'event.properties')->where('report.event', 'purchase'));
        $this->actingAs(User::factory()->create())->get('/sites/'.$this->site->id.'/events/properties')->assertForbidden();
        $this->actingAs(User::factory()->create())->getJson('/api/sites/'.$this->site->id.'/events/properties')->assertForbidden();
        $this->actingAs(User::factory()->create())->get('/sites/'.$this->site->id.'/events/properties/export')->assertForbidden();
    }

    public function test_guests_are_sent_to_the_login(): void
    {
        $this->get('/sites/'.$this->site->id.'/events/properties')->assertRedirect();
        $this->get('/sites/'.$this->site->id.'/events/properties/export')->assertRedirect();
    }

    public function test_a_deleting_site_is_gone(): void
    {
        DB::table('websites')->where('id', $this->site->id)->update(['deleting_at' => now()]);

        $this->actingAs($this->owner)->get('/sites/'.$this->site->id.'/events/properties')->assertNotFound();
    }

    public function test_the_csv_neutralises_formulas_in_keys_and_values_and_leaves_numbers(): void
    {
        $this->named('purchase', ['payment' => "=cmd|' /C calc'!A0"], 1500);
        $this->named('purchase', ['payment' => '+SUM(1)']);
        $this->named('purchase', ['payment' => '-2+3']);
        $this->named('purchase', ['payment' => '@x']);
        $this->named('purchase', ['payment' => 'fine']);
        app(Aggregator::class)->run();
        DB::table('stats_event_property_daily')->where('prop_value', 'fine')->update(['prop_value' => "\t=1"]);

        $csv = $this->actingAs($this->owner)->get('/sites/'.$this->site->id.'/events/properties/export?from=2026-10-20&to=2026-10-26')->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->streamedContent();

        $rows = array_map('str_getcsv', array_filter(explode("\n", $csv)));
        $this->assertSame(['event', 'property', 'value', 'count', 'value_total', 'value_average'], $rows[0]);
        $values = array_column(array_slice($rows, 1), 2);
        sort($values);
        $expected = ["'+SUM(1)", "'-2+3", "'=cmd|' /C calc'!A0", "'@x", "'\t=1"];
        sort($expected);
        $this->assertSame($expected, $values);
        $formula = collect($rows)->first(fn (array $row): bool => str_starts_with($row[2], "'=cmd"));
        $this->assertSame(['purchase', 'payment', "'=cmd|' /C calc'!A0", '1', '1.5', '1.5'], $formula);
    }

    public function test_the_properties_are_not_part_of_any_public_share(): void
    {
        $this->named('purchase', ['payment' => 'card'], 100);
        app(Aggregator::class)->run();
        [$link, $token] = ShareLink::issue($this->site, null, 'All', array_keys(ShareLink::SECTIONS), null, null);
        $this->assertNotNull($link);

        $this->get('/s/'.$token.'/events/properties')->assertNotFound();
        $this->get('/s/'.$token.'/api/properties')->assertNotFound();
        $this->get('/sites/'.$this->site->id.'/events/properties?token='.$token)->assertRedirect();
        $this->assertSame(['overview', 'pages', 'sources', 'campaigns', 'devices', 'countries', 'events'], array_keys(ShareLink::SECTIONS));
    }

    public function test_every_public_payload_is_free_of_values_and_properties(): void
    {
        $this->named('purchase', ['payment' => 'card'], 100);
        app(Aggregator::class)->run();
        [, $token] = ShareLink::issue($this->site, null, 'All', array_keys(ShareLink::SECTIONS), null, null);

        $pages = [$this->get('/s/'.$token.'?from=2026-10-20&to=2026-10-26')->assertOk()->getContent()];
        foreach (['event', 'path', 'source', 'country'] as $dimension) {
            $pages[] = $this->get('/s/'.$token.'?dimension='.$dimension.'&from=2026-10-20&to=2026-10-26')->assertOk()->getContent();
            $pages[] = $this->getJson('/s/'.$token.'/api/dimensions?dimension='.$dimension.'&from=2026-10-20&to=2026-10-26')->getContent();
        }
        foreach (['overview', 'timeseries'] as $report) {
            $pages[] = $this->getJson('/s/'.$token.'/api/'.$report.'?from=2026-10-20&to=2026-10-26')->assertOk()->getContent();
        }
        foreach ($pages as $page) {
            foreach (['event_props', 'event_value', 'value_sum', 'valued', 'amount', 'payment'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $page);
            }
        }
    }
}
