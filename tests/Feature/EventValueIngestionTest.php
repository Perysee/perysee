<?php

namespace Tests\Feature;

use App\Installation\InstallationState;
use App\Models\Website;
use App\Support\Coordination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class EventValueIngestionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
    }

    private function event(Website $site, array $extra = [], array $headers = []): TestResponse
    {
        $mode = $site->tracking_mode ?: 'pageviews';
        $payload = array_merge(['v' => 1, 'site' => $site->public_id, 'type' => 'event', 'mode' => $mode, 'host' => $site->domain, 'path' => '/thanks', 'consent' => true, 'event_name' => 'purchase'], $mode === 'sessions' ? ['session' => str_repeat('a', 32)] : [], $extra);

        return $this->withHeaders($headers + ['Origin' => 'https://'.$site->domain])->postJson('/api/collect', $payload);
    }

    /**
     * @return list<string>
     */
    private function queriesDuring(callable $work): array
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $work();

        return $queries;
    }

    public function test_an_event_without_value_keeps_the_previous_contract(): void
    {
        $site = Website::factory()->create();

        $this->event($site)->assertNoContent()->assertHeaderMissing('X-Perysee-Truncated')->assertHeaderMissing('X-Perysee-Duplicate');

        $row = DB::table('events')->where('website_id', $site->id)->first();
        $this->assertNull($row->event_value);
        $this->assertNull($row->event_props);
        $this->assertSame('purchase', $row->event_name);
        $this->assertSame(0, DB::table('event_order_ids')->count());
    }

    public function test_a_value_is_stored_in_thousandths(): void
    {
        $site = Website::factory()->create();

        $this->event($site, ['value' => 49.9])->assertNoContent();
        $this->event($site, ['value' => 0])->assertNoContent();
        $this->event($site, ['value' => 12, 'event_name' => 'upgrade'])->assertNoContent();
        $this->event($site, ['value' => 0.0004, 'event_name' => 'tiny'])->assertNoContent();

        $values = DB::table('events')->where('website_id', $site->id)->orderBy('id')->pluck('event_value')->map(fn ($value) => (int) $value)->all();
        $this->assertSame([49900, 0, 12000, 0], $values);
    }

    public function test_the_largest_accepted_value_fits_the_column(): void
    {
        $site = Website::factory()->create();

        $this->event($site, ['value' => 1000000000])->assertNoContent();

        $this->assertSame(1000000000000, (int) DB::table('events')->value('event_value'));
    }

    public function test_invalid_values_refuse_the_event(): void
    {
        $site = Website::factory()->create();

        foreach ([-1, -0.01, 1000000000.01, '49.90', 'abc', true, [1], ['a' => 1]] as $bad) {
            $this->event($site, ['value' => $bad])->assertUnprocessable();
        }
        $this->call('POST', '/api/collect', server: ['CONTENT_TYPE' => 'text/plain', 'HTTP_ORIGIN' => 'https://'.$site->domain], content: '{"v":1,"site":"'.$site->public_id.'","type":"event","mode":"pageviews","host":"'.$site->domain.'","path":"/","consent":true,"event_name":"purchase","value":1e999}')->assertUnprocessable();

        $this->assertSame(0, DB::table('events')->count());
    }

    public function test_a_null_value_is_the_same_as_no_value(): void
    {
        $site = Website::factory()->create();

        $this->event($site, ['value' => null, 'props' => null, 'id' => null])->assertNoContent();

        $this->assertNull(DB::table('events')->value('event_value'));
        $this->assertSame(0, DB::table('event_order_ids')->count());
    }

    public function test_value_properties_and_identifier_are_refused_on_a_pageview(): void
    {
        $site = Website::factory()->create();

        foreach (['value' => 5, 'props' => ['a' => 'b'], 'id' => 'o-1'] as $field => $content) {
            $this->event($site, ['type' => 'pageview', 'event_name' => null, $field => $content])->assertUnprocessable();
        }
        $this->assertSame(0, DB::table('events')->count());
    }

    public function test_a_refused_value_is_counted_as_a_rejection(): void
    {
        $site = Website::factory()->create();
        $before = app(Coordination::class)->failures('collection_rejections');

        $this->event($site, ['value' => -1])->assertUnprocessable();

        $this->assertSame($before + 1, app(Coordination::class)->failures('collection_rejections'));
    }

    public function test_unknown_fields_are_still_refused(): void
    {
        $site = Website::factory()->create();

        $this->event($site, ['currency' => 'EUR'])->assertUnprocessable();
        $this->event($site, ['email' => 'a@example.com'])->assertUnprocessable();
    }

    public function test_the_collection_path_costs_the_same_number_of_queries_with_or_without_value(): void
    {
        $site = Website::factory()->create();
        $this->event($site)->assertNoContent();

        $plain = $this->queriesDuring(fn () => $this->event($site)->assertNoContent());
        $valued = $this->queriesDuring(fn () => $this->event($site, ['value' => 10, 'props' => ['payment' => 'card', 'shipping' => 'standard']])->assertNoContent());

        $this->assertCount(count($plain), $valued, implode(' | ', $valued));
        $this->assertSame(1, count(array_filter($valued, fn (string $sql): bool => (bool) preg_match('/^insert into [`"]events[`"]/i', $sql))));
        $this->assertEmpty(array_filter($valued, fn (string $sql): bool => str_contains($sql, 'event_order_ids')));
    }

    public function test_the_query_count_of_a_plain_event_is_the_one_measured_before_this_feature(): void
    {
        $site = Website::factory()->create();
        $this->event($site)->assertNoContent();

        $plain = $this->queriesDuring(fn () => $this->event($site)->assertNoContent());

        $this->assertCount(2, $plain, implode(' | ', $plain));
    }

    public function test_a_valued_event_works_in_sessions_mode_too(): void
    {
        $site = Website::factory()->create(['tracking_mode' => 'sessions']);

        $this->event($site, ['value' => 3.5])->assertNoContent();

        $this->assertSame(3500, (int) DB::table('events')->value('event_value'));
        $this->assertNotNull(DB::table('events')->value('session_key'));
    }
}
