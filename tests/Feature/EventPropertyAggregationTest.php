<?php

namespace Tests\Feature;

use App\Analytics\Aggregation\Aggregator;
use App\Analytics\Aggregation\EventPropertyStats;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EventPropertyAggregationTest extends TestCase
{
    use RefreshDatabase;

    private function named(Website $site, string $name, array $props, ?int $thousandths = null, string $time = '2026-10-24 10:00:00'): int
    {
        return DB::table('events')->insertGetId(['website_id' => $site->id, 'received_at' => $time, 'event_type' => 2, 'event_name' => $name, 'path' => '/', 'event_value' => $thousandths, 'event_props' => $props === [] ? null : json_encode($props), 'consent_given' => true]);
    }

    private function rows(Website $site, string $name = 'purchase', ?string $key = null)
    {
        return DB::table('stats_event_property_daily')->where('website_id', $site->id)->where('event_name', $name)->when($key, fn ($query) => $query->where('prop_key', $key))->orderBy('prop_key')->orderBy('prop_value')->get();
    }

    public function test_counts_and_values_are_summed_per_property_value(): void
    {
        $site = Website::factory()->create();
        $this->named($site, 'purchase', ['payment' => 'card', 'plan' => 'pro'], 10000);
        $this->named($site, 'purchase', ['payment' => 'card'], 5000);
        $this->named($site, 'purchase', ['payment' => 'paypal'], null);
        $this->named($site, 'purchase', [], 999);

        app(Aggregator::class)->run();

        $card = $this->rows($site, 'purchase', 'payment')->firstWhere('prop_value', 'card');
        $this->assertSame([2, 15000, 2], [(int) $card->metric_count, (int) $card->value_sum, (int) $card->valued_count]);
        $paypal = $this->rows($site, 'purchase', 'payment')->firstWhere('prop_value', 'paypal');
        $this->assertSame([1, 0, 0], [(int) $paypal->metric_count, (int) $paypal->value_sum, (int) $paypal->valued_count]);
        $this->assertSame(1, (int) $this->rows($site, 'purchase', 'plan')->first()->metric_count);
        $this->assertSame('2026-10-24', $card->stat_date);
    }

    public function test_the_day_follows_the_timezone_of_the_site(): void
    {
        $site = Website::factory()->create(['timezone' => 'Pacific/Auckland']);
        $this->named($site, 'purchase', ['a' => 'b'], null, '2026-10-24 20:00:00');

        app(Aggregator::class)->run();

        $this->assertSame('2026-10-25', $this->rows($site)->first()->stat_date);
    }

    public function test_events_without_properties_cost_no_query(): void
    {
        $site = Website::factory()->create();
        foreach (range(1, 20) as $i) {
            $this->named($site, 'signup', []);
        }
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        app(Aggregator::class)->run();

        $this->assertEmpty(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'stats_event_property_daily')));
    }

    public function test_a_batch_with_properties_adds_two_reads_and_one_write(): void
    {
        $site = Website::factory()->create();
        foreach (range(1, 60) as $i) {
            $this->named($site, 'purchase', ['payment' => $i % 2 ? 'card' : 'paypal', 'plan' => 'p'.($i % 5)], $i * 100);
        }
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        app(Aggregator::class)->run();

        $mine = array_filter($queries, fn (string $sql): bool => str_contains($sql, 'stats_event_property_daily'));
        $this->assertCount(3, $mine, implode(' | ', $mine));
    }

    public function test_more_than_fifty_values_a_day_overflow_into_other_with_an_exact_total(): void
    {
        $site = Website::factory()->create();
        foreach (range(1, 60) as $i) {
            $this->named($site, 'purchase', ['sku' => 'sku'.$i], 1000);
        }

        app(Aggregator::class)->run();

        $rows = $this->rows($site, 'purchase', 'sku');
        $this->assertCount(51, $rows);
        $this->assertSame(10, (int) $rows->firstWhere('prop_value', '(other)')->metric_count);
        $this->assertSame(60, (int) $rows->sum('metric_count'));
        $this->assertSame(60000, (int) $rows->sum('value_sum'));
        $this->assertSame(1, (int) $rows->firstWhere('prop_value', 'sku1')->metric_count);
    }

    public function test_a_known_value_is_still_counted_when_the_day_is_full(): void
    {
        $site = Website::factory()->create();
        foreach (range(1, 50) as $i) {
            $this->named($site, 'purchase', ['sku' => 'sku'.$i]);
        }
        app(Aggregator::class)->run();
        $this->named($site, 'purchase', ['sku' => 'sku7']);
        $this->named($site, 'purchase', ['sku' => 'new']);

        app(Aggregator::class)->run();

        $rows = $this->rows($site, 'purchase', 'sku');
        $this->assertSame(2, (int) $rows->firstWhere('prop_value', 'sku7')->metric_count);
        $this->assertNull($rows->firstWhere('prop_value', 'new'));
        $this->assertSame(1, (int) $rows->firstWhere('prop_value', '(other)')->metric_count);
    }

    public function test_the_bound_on_values_applies_per_day(): void
    {
        $site = Website::factory()->create();
        foreach (range(1, 50) as $i) {
            $this->named($site, 'purchase', ['sku' => 'sku'.$i]);
        }
        $this->named($site, 'purchase', ['sku' => 'fresh'], null, '2026-10-25 10:00:00');

        app(Aggregator::class)->run();

        $this->assertSame(1, (int) $this->rows($site, 'purchase', 'sku')->firstWhere('prop_value', 'fresh')->metric_count);
    }

    public function test_the_eleventh_key_of_an_event_is_counted_as_other(): void
    {
        $site = Website::factory()->create();
        $keys = array_map(fn (int $i): string => 'k'.chr(96 + $i), range(1, 12));
        foreach (array_chunk($keys, 5) as $chunk) {
            $this->named($site, 'purchase', array_fill_keys($chunk, 'x'), 100);
        }

        app(Aggregator::class)->run();

        $found = $this->rows($site)->pluck('prop_key')->unique()->values()->all();
        $this->assertCount(11, $found);
        $this->assertContains('(other)', $found);
        $this->assertSame(2, (int) $this->rows($site, 'purchase', '(other)')->sum('metric_count'));
        $this->assertSame(200, (int) $this->rows($site, 'purchase', '(other)')->sum('value_sum'));
    }

    public function test_the_hundred_first_event_name_is_counted_as_other(): void
    {
        $site = Website::factory()->create();
        foreach (range(1, 102) as $i) {
            $this->named($site, 'event'.$i, ['a' => 'b']);
        }

        app(Aggregator::class)->run();

        $this->assertSame(100, DB::table('stats_event_property_daily')->where('event_name', '!=', '(other)')->distinct()->count('event_name'));
        $this->assertSame(2, (int) DB::table('stats_event_property_daily')->where('event_name', '(other)')->sum('metric_count'));
    }

    public function test_bounds_are_kept_per_site(): void
    {
        $a = Website::factory()->create();
        $b = Website::factory()->create();
        foreach (range(1, 100) as $i) {
            $this->named($a, 'event'.$i, ['a' => 'b']);
        }
        $this->named($b, 'mine', ['a' => 'b']);

        app(Aggregator::class)->run();

        $this->assertSame(1, $this->rows($b, 'mine')->count());
        $this->assertSame(0, $this->rows($b, '(other)')->count());
    }

    public function test_the_same_batch_twice_does_not_double_count(): void
    {
        $site = Website::factory()->create();
        $this->named($site, 'purchase', ['payment' => 'card'], 100);

        app(Aggregator::class)->run();
        app(Aggregator::class)->run();

        $this->assertSame(1, (int) $this->rows($site)->first()->metric_count);
    }

    public function test_an_interrupted_batch_leaves_nothing_behind_and_resumes_without_double_counting(): void
    {
        $site = Website::factory()->create();
        $this->named($site, 'purchase', ['payment' => 'card'], 100);
        $this->named($site, 'purchase', ['payment' => 'card'], 100);
        $armed = true;
        DB::listen(function ($query) use (&$armed): void {
            if ($armed && str_contains($query->sql, 'update') && str_contains($query->sql, 'processed_at')) {
                $armed = false;

                throw new \RuntimeException('killed');
            }
        });

        try {
            app(Aggregator::class)->run();
            $this->fail('The batch should have been interrupted.');
        } catch (\RuntimeException) {
        }
        $this->assertSame(0, DB::table('stats_event_property_daily')->count());
        $this->assertSame(2, DB::table('events')->whereNull('processed_at')->count());

        app(Aggregator::class)->run();
        app(Aggregator::class)->run();

        $this->assertSame(2, (int) $this->rows($site)->first()->metric_count);
        $this->assertSame(200, (int) $this->rows($site)->first()->value_sum);
    }

    public function test_the_overflow_bucket_name_cannot_be_forged_by_a_value(): void
    {
        $site = Website::factory()->create();
        $this->named($site, 'purchase', ['plan' => 'other']);

        app(Aggregator::class)->run();

        $this->assertSame('other', $this->rows($site)->first()->prop_value);
        $this->assertSame(50, EventPropertyStats::MAX_VALUES);
    }

    public function test_small_chunks_are_all_written(): void
    {
        $site = Website::factory()->create();
        foreach (range(1, 30) as $i) {
            $this->named($site, 'purchase', ['a' => 'v'.($i % 30), 'b' => 'w'.($i % 7), 'c' => 'x'.($i % 3)]);
        }

        app(Aggregator::class)->run(10);
        app(Aggregator::class)->run(10);
        app(Aggregator::class)->run(10);

        $this->assertSame(30, (int) $this->rows($site, 'purchase', 'a')->sum('metric_count'));
        $this->assertSame(30, (int) $this->rows($site, 'purchase', 'b')->sum('metric_count'));
    }
}
