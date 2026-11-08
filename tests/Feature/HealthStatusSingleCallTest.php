<?php

namespace Tests\Feature;

use App\Analytics\Ingestion\Geolocation;
use App\Installation\InstallationState;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HealthStatusSingleCallTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_health_page_reads_the_country_database_status_once(): void
    {
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
        $counter = new class extends Geolocation
        {
            public int $calls = 0;

            public function status(): array
            {
                $this->calls++;

                return parent::status();
            }
        };
        $this->app->instance(Geolocation::class, $counter);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get('/health')->assertInertia(fn (Assert $page) => $page
            ->has('geolocation.available')
            ->has('health.geolocation.available'));

        $this->assertSame(1, $counter->calls);
    }
}
