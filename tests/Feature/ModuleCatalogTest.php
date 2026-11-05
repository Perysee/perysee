<?php

namespace Tests\Feature;

use App\Modules\Catalog\CatalogEntry;
use App\Modules\Catalog\CatalogSignature;
use App\Modules\Catalog\ModuleCatalog;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ModuleCatalogTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://api.perysee.test/api/v1/catalog/modules';

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $pair = sodium_crypto_sign_keypair();
        $this->secret = sodium_crypto_sign_secretkey($pair);
        config(['perysee.module_catalog' => ['enabled' => true, 'url' => self::URL, 'keys_path' => '']]);
        $this->app->instance(CatalogSignature::class, new CatalogSignature(['k1' => base64_encode(sodium_crypto_sign_publickey($pair))]));
        $this->app->forgetInstance(ModuleCatalog::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(string $slug = 'example', array $overrides = []): array
    {
        $entry = $overrides + [
            'slug' => $slug, 'name' => ucfirst($slug), 'description' => 'A module.', 'version' => '1.0.0', 'requiredPlan' => 'free',
            'stability' => 'beta', 'tier' => 'official', 'api' => 1, 'core' => '0.1.0',
            'downloadUrl' => "https://github.com/Perysee/app/releases/download/v0.2.0/{$slug}-1.0.0.zip", 'sha256' => str_repeat('a1', 32), 'keyId' => 'k1',
        ];
        $payload = CatalogEntry::canonicalPayload($entry);

        return $entry + ['signature' => base64_encode(sodium_crypto_sign_detached($payload, $this->secret))];
    }

    private function catalog(): ModuleCatalog
    {
        return $this->app->make(ModuleCatalog::class);
    }

    public function test_the_catalog_is_fetched_without_any_identity_and_remembered(): void
    {
        Http::fake([self::URL => Http::response(['data' => [$this->entry('example'), $this->entry('redis')]])]);

        $state = $this->catalog()->refresh();

        $this->assertNull($state['error']);
        $this->assertSame(['example', 'redis'], array_map(fn ($entry) => $entry->slug, $this->catalog()->entries()));
        $this->assertNotNull($this->catalog()->stored()['checked_at']);
        Http::assertSent(function (Request $request): bool {
            return $request->url() === self::URL
                && str_starts_with($request->header('User-Agent')[0], 'Perysee/')
                && $request->header('Authorization') === []
                && $request->header('Cookie') === [];
        });
    }

    public function test_an_unreachable_catalog_keeps_the_previous_answer_and_records_why(): void
    {
        Http::fake([self::URL => Http::sequence()->push(['data' => [$this->entry()]])->pushFailedConnection()]);
        $this->catalog()->refresh();

        $state = $this->catalog()->refresh();

        $this->assertNotNull($state['error']);
        $this->assertCount(1, $this->catalog()->entries());
    }

    public function test_an_http_error_or_an_invalid_body_is_reported_and_changes_nothing(): void
    {
        Http::fake([self::URL => Http::sequence()->push(['data' => [$this->entry()]])->push('oops', 500)->push('not json', 200)->push(['unexpected' => true])]);
        $this->catalog()->refresh();

        foreach ([500, 200, 200] as $ignored) {
            $this->assertNotNull($this->catalog()->refresh()['error']);
            $this->assertCount(1, $this->catalog()->entries());
        }
    }

    public function test_one_malformed_entry_is_skipped_without_losing_the_others(): void
    {
        Http::fake([self::URL => Http::response(['data' => [$this->entry('good'), ['slug' => '../bad'], $this->entry('also-good'), 'text']])]);

        $this->catalog()->refresh();

        $this->assertSame(['good', 'also-good'], array_map(fn ($entry) => $entry->slug, $this->catalog()->entries()));
    }

    public function test_a_disabled_catalog_makes_no_network_call(): void
    {
        config(['perysee.module_catalog.enabled' => false]);
        Http::fake();

        $this->assertFalse($this->catalog()->enabled());
        $this->catalog()->refresh();

        Http::assertNothingSent();
        $this->assertSame([], $this->catalog()->entries());
    }

    public function test_a_catalog_address_without_https_is_refused_before_any_call(): void
    {
        Http::fake();
        config(['perysee.module_catalog.url' => 'http://catalog.example.com/modules']);

        $this->assertNotNull($this->catalog()->refresh()['error']);

        Http::assertNothingSent();
    }

    public function test_a_local_test_server_may_use_plain_http(): void
    {
        Http::fake(['http://127.0.0.1:3333/*' => Http::response(['data' => [$this->entry()]])]);
        config(['perysee.module_catalog.url' => 'http://127.0.0.1:3333/api/v1/catalog/modules']);

        $this->assertNull($this->catalog()->refresh()['error']);
        $this->assertCount(1, $this->catalog()->entries());
    }

    public function test_an_oversized_answer_is_refused(): void
    {
        Http::fake([self::URL => Http::response(str_repeat('x', 600 * 1024), 200, ['Content-Type' => 'application/json'])]);

        $this->assertNotNull($this->catalog()->refresh()['error']);
        $this->assertSame([], $this->catalog()->entries());
    }

    public function test_the_modules_command_lists_the_catalog_and_the_schedule_checks_it_daily(): void
    {
        Http::fake([self::URL => Http::response(['data' => [$this->entry('example')]])]);

        $this->artisan('perysee:modules', ['action' => 'catalog'])
            ->expectsOutputToContain('example 1.0.0 official beta')
            ->assertSuccessful();

        $scheduled = collect(app(Schedule::class)->events())->map(fn ($event) => $event->command)->implode("\n");
        $this->assertStringContainsString('perysee:modules catalog --refresh-only', $scheduled);
    }

    public function test_the_command_says_when_the_catalog_is_off(): void
    {
        config(['perysee.module_catalog.enabled' => false]);

        $this->artisan('perysee:modules', ['action' => 'catalog'])->expectsOutputToContain('PERYSEE_MODULE_CATALOG')->assertSuccessful();
    }
}
