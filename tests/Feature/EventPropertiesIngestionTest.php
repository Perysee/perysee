<?php

namespace Tests\Feature;

use App\Analytics\Ingestion\PropertyPolicy;
use App\Installation\InstallationState;
use App\Models\Website;
use App\Support\Coordination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EventPropertiesIngestionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
    }

    private function event(Website $site, array $extra = []): \Illuminate\Testing\TestResponse
    {
        $payload = array_merge(['v' => 1, 'site' => $site->public_id, 'type' => 'event', 'mode' => 'pageviews', 'host' => $site->domain, 'path' => '/thanks', 'consent' => true, 'event_name' => 'purchase'], $extra);

        return $this->withHeader('Origin', 'https://'.$site->domain)->postJson('/api/collect', $payload);
    }

    /**
     * @return array<string, string>
     */
    private function stored(): array
    {
        return json_decode((string) DB::table('events')->orderByDesc('id')->value('event_props'), true) ?? [];
    }

    public function test_properties_are_stored_as_canonical_json(): void
    {
        $site = Website::factory()->create();

        $this->event($site, ['props' => ['shipping' => 'standard', 'Payment' => 'card']])->assertNoContent()->assertHeaderMissing('X-Perysee-Truncated');

        $this->assertSame('{"payment":"card","shipping":"standard"}', DB::table('events')->value('event_props'));
    }

    public function test_numbers_and_booleans_become_text(): void
    {
        $site = Website::factory()->create();

        $this->event($site, ['props' => ['tier' => 3, 'gift' => true, 'ratio' => 0.5]])->assertNoContent();

        $this->assertSame(['gift' => 'true', 'ratio' => '0.5', 'tier' => '3'], $this->stored());
    }

    public function test_an_empty_object_stores_nothing(): void
    {
        $site = Website::factory()->create();

        $this->event($site, ['props' => []])->assertNoContent();

        $this->assertNull(DB::table('events')->value('event_props'));
    }

    public function test_more_than_five_properties_keep_the_first_five_and_say_so(): void
    {
        $site = Website::factory()->create();
        $before = app(Coordination::class)->failures('property_truncations');

        $this->event($site, ['props' => ['a' => '1', 'b' => '2', 'c' => '3', 'd' => '4', 'e' => '5', 'f' => '6', 'g' => '7']])
            ->assertNoContent()->assertHeader('X-Perysee-Truncated', 'props=2;reason=too_many');

        $this->assertSame(['a', 'b', 'c', 'd', 'e'], array_keys($this->stored()));
        $this->assertSame(1, DB::table('events')->count());
        $this->assertSame($before + 1, app(Coordination::class)->failures('property_truncations'));
    }

    public function test_invalid_keys_and_values_are_dropped_without_losing_the_event(): void
    {
        $site = Website::factory()->create();

        $this->event($site, ['props' => ['1bad' => 'x', 'has space' => 'x', str_repeat('k', 33) => 'x', 'ok' => 'fine', 'long' => str_repeat('v', 65), 'empty' => '  ', 'nested' => ['a' => 1], 'nothing' => null, 'Ok' => 'again']])
            ->assertNoContent()->assertHeader('X-Perysee-Truncated');

        $this->assertSame(['ok' => 'fine'], $this->stored());
        $this->assertSame(1, DB::table('events')->count());
    }

    public function test_a_value_of_exactly_64_characters_is_kept(): void
    {
        $site = Website::factory()->create();

        $this->event($site, ['props' => ['label' => str_repeat('é', 64)]])->assertNoContent()->assertHeaderMissing('X-Perysee-Truncated');

        $this->assertSame(str_repeat('é', 64), $this->stored()['label']);
    }

    public function test_keys_that_name_a_person_keep_their_key_and_lose_their_value(): void
    {
        $site = Website::factory()->create();

        $this->event($site, ['props' => ['email' => 'harmless', 'Phone' => '1', 'birth_year' => '1980', 'postal_code' => '75001', 'plan' => 'pro']])
            ->assertNoContent()->assertHeader('X-Perysee-Truncated', 'props=4;reason=redacted');

        $this->assertSame(['birth_year' => ':redacted', 'email' => ':redacted', 'phone' => ':redacted', 'plan' => 'pro', 'postal_code' => ':redacted'], $this->stored());
    }

    public function test_values_that_look_personal_are_masked(): void
    {
        $site = Website::factory()->create();
        $personal = ['at' => 'jane@example.com', 'phone' => 'call 06 12 34 56 78', 'card' => '4111-1111-1111-1111', 'iban' => 'FR76 3000 6000 0112 3456 7890 189', 'hex' => str_repeat('ab', 16), 'control' => "a\tb", 'order' => '123456789'];

        foreach (array_chunk($personal, 1, true) as $one) {
            $this->event($site, ['props' => $one])->assertNoContent();
            $this->assertSame([':redacted'], array_values($this->stored()), json_encode($one));
        }
    }

    public function test_ordinary_values_are_not_mistaken_for_personal_data(): void
    {
        $site = Website::factory()->create();

        $this->event($site, ['props' => ['price' => '1234.56', 'tier' => 'us12standardshipping', 'sku' => 'A-1234', 'when' => '2026-11-08']])->assertNoContent()->assertHeaderMissing('X-Perysee-Truncated');

        $this->assertSame(['price' => '1234.56', 'sku' => 'A-1234', 'tier' => 'us12standardshipping', 'when' => '2026-11-08'], $this->stored());
    }

    public function test_the_reserved_word_of_the_aggregates_is_not_a_value(): void
    {
        $site = Website::factory()->create();

        $this->event($site, ['props' => ['plan' => '(other)']])->assertNoContent();

        $this->assertSame(['plan' => 'other'], $this->stored());
    }

    public function test_the_stored_json_never_exceeds_one_kilobyte(): void
    {
        $site = Website::factory()->create();

        $this->event($site, ['props' => ['a' => str_repeat('𝒳', 64), 'b' => str_repeat('𝒳', 64), 'c' => str_repeat('𝒳', 64), 'd' => str_repeat('𝒳', 64), 'e' => str_repeat('𝒳', 64)]])->assertNoContent()->assertHeader('X-Perysee-Truncated');

        $this->assertLessThanOrEqual(1024, strlen(DB::table('events')->value('event_props')));
        $this->assertSame(1, DB::table('events')->count());
    }

    public function test_properties_that_are_not_an_object_refuse_the_event(): void
    {
        $site = Website::factory()->create();

        foreach (['text', 5, true, ['a', 'b'], [['a' => 1]]] as $bad) {
            $this->event($site, ['props' => $bad])->assertUnprocessable();
        }
        $this->assertSame(0, DB::table('events')->count());
    }

    public function test_the_body_limit_still_applies_to_properties(): void
    {
        $site = Website::factory()->create();

        $this->event($site, ['props' => ['a' => str_repeat('x', 5000)]])->assertStatus(413);
        $this->assertSame(0, DB::table('events')->count());
    }

    public function test_the_truncation_header_can_be_read_by_the_tracker(): void
    {
        $site = Website::factory()->create();

        $this->event($site, ['props' => ['email' => 'x']])->assertHeader('Access-Control-Expose-Headers', 'X-Perysee-Truncated');
    }
}
