<?php

namespace Tests\Feature;

use App\Analytics\Aggregation\Aggregator;
use App\Analytics\Ingestion\Geolocation;
use App\Installation\InstallationState;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class GeolocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_or_corrupt_database_degrades_without_interrupting_collection(): void
    {
        config(['perysee.geoip_path' => __FILE__]);
        $this->assertSame(['country_code' => null, 'geo_available' => false], app(Geolocation::class)->lookup('8.8.8.8'));
        config(['perysee.geoip_path' => '/missing/country.mmdb']);
        $this->assertFalse(app(Geolocation::class)->lookup('8.8.8.8')['geo_available']);
        $site = Website::factory()->create();
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
        $this->withHeaders(['Origin' => 'https://'.$site->domain])->postJson('/api/collect', ['v' => 1, 'site' => $site->public_id, 'type' => 'pageview', 'mode' => 'pageviews', 'host' => $site->domain, 'path' => '/', 'consent' => true, 'ref' => null])->assertNoContent();
        $this->assertDatabaseHas('events', ['geo_available' => false, 'country_code' => null]);
    }

    public function test_collection_stores_only_country_and_protects_against_untrusted_forwarding(): void
    {
        $geo = $this->mock(Geolocation::class);
        $geo->shouldReceive('lookup')->once()->with('127.0.0.1')->andReturn(['country_code' => 'FR', 'geo_available' => true]);
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
        $site = Website::factory()->create(['timezone' => 'UTC']);
        $user = User::factory()->create();
        $site->members()->attach($user, ['role' => 'viewer']);
        $payload = ['v' => 1, 'site' => $site->public_id, 'type' => 'pageview', 'mode' => 'pageviews', 'host' => $site->domain, 'path' => '/', 'consent' => true, 'ref' => null];
        $this->withHeaders(['Origin' => 'https://'.$site->domain, 'X-Forwarded-For' => '8.8.8.8'])->postJson('/api/collect', $payload)->assertNoContent();
        $this->assertDatabaseHas('events', ['country_code' => 'FR']);
        $this->postJson('/api/collect', $payload + ['country_code' => 'US'])->assertUnprocessable();
        app(Aggregator::class)->run();
        $this->assertDatabaseHas('stats_daily', ['country_pageviews' => 1]);
        $this->actingAs($user)->getJson('/api/sites/'.$site->id.'/dimensions?period=today&dimension=country')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.value', 'FR');
        $this->get('/sites/'.$site->id.'/countries?period=today')->assertOk()->assertInertia(fn (Assert $page) => $page->where('section', 'countries')->where('dimensionReport.data.0.value', 'FR')->where('report.coverage.country_pageviews', 1));
        $this->get('/sites/'.$site->id.'/export?period=today&dimension=country&value=FR')->assertOk();
    }

    public function test_bundled_database_resolves_ipv4_ipv6_and_keeps_private_addresses_unknown(): void
    {
        $geo = app(Geolocation::class);
        $this->assertSame('US', $geo->lookup('8.8.8.8')['country_code']);
        $this->assertTrue($geo->lookup('2606:4700:4700::1111')['geo_available']);
        foreach (['127.0.0.1', '10.0.0.1', '::1', '192.0.2.1', 'invalid'] as $ip) {
            $this->assertNull($geo->lookup($ip)['country_code']);
        }
    }

    public function test_admin_import_validates_and_preserves_the_previous_database_on_failure(): void
    {
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
        config(['perysee.geoip_directory' => $this->installationDirectory.'/geoip', 'cache.stores.file.lock_path' => $this->installationDirectory.'/locks']);
        $file = UploadedFile::fake()->createWithContent('country.mmdb', file_get_contents(resource_path('geoip/country.mmdb')));
        $this->actingAs(User::factory()->create())->post('/health/geolocation', ['database' => $file])->assertForbidden();
        $this->actingAs(User::factory()->create(['is_admin' => true]))->post('/health/geolocation', ['database' => $file])->assertSessionHasNoErrors()->assertRedirect();
        $before = hash_file('sha256', $this->installationDirectory.'/geoip/country.mmdb');
        $invalid = UploadedFile::fake()->createWithContent('bad.mmdb', 'invalid database');
        $this->post('/health/geolocation', ['database' => $invalid])->assertSessionHasErrors('database');
        $this->assertSame($before, hash_file('sha256', $this->installationDirectory.'/geoip/country.mmdb'));
        $this->post('/health/geolocation', ['database' => $file])->assertSessionHasNoErrors();
        $this->assertSame($before, hash_file('sha256', $this->installationDirectory.'/geoip/previous.mmdb'));
    }

    public function test_only_explicitly_configured_proxies_forward_the_client_ip(): void
    {
        config(['perysee.trusted_proxies' => ['127.0.0.1']]);
        $geo = $this->mock(Geolocation::class);
        $geo->shouldReceive('lookup')->once()->with('8.8.8.8')->andReturn(['country_code' => 'US', 'geo_available' => true]);
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
        $site = Website::factory()->create();
        $this->withHeaders(['Origin' => 'https://'.$site->domain, 'X-Forwarded-For' => '8.8.8.8'])->postJson('/api/collect', ['v' => 1, 'site' => $site->public_id, 'type' => 'pageview', 'mode' => 'pageviews', 'host' => $site->domain, 'path' => '/', 'consent' => true, 'ref' => null])->assertNoContent();
    }

    public function test_the_database_is_hashed_once_per_version_of_the_file_whatever_the_number_of_calls(): void
    {
        config(['cache.stores.file.path' => $this->installationDirectory.'/cache', 'cache.stores.file.lock_path' => $this->installationDirectory.'/locks', 'perysee.geoip_directory' => $this->installationDirectory.'/geoip']);
        $counting = fn (): Geolocation => new class extends Geolocation
        {
            public static int $hashes = 0;

            protected function digest(string $path): string
            {
                self::$hashes++;

                return parent::digest($path);
            }
        };
        $counting()::$hashes = 0;
        $first = $counting()->status();

        $this->assertSame(hash_file('sha256', resource_path('geoip/country.mmdb')), $first['sha256']);
        $this->assertSame($first, $counting()->status());
        $geo = $counting();
        $this->assertSame($first, $geo->status());
        $this->assertSame(1, $geo::$hashes);

        // An import replaces the file: the next status reads the new one, every time.
        $file = UploadedFile::fake()->createWithContent('country.mmdb', file_get_contents(resource_path('geoip/country.mmdb')));
        $geo->import($file);
        $geo->import($file);
        $this->assertSame($first['sha256'], $geo->status()['sha256']);
        $this->assertSame(2, $geo::$hashes);
    }
}
