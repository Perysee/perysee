<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Website;
use Database\Seeders\LocalDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocalDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_seeding_is_repeatable_and_keeps_existing_accounts(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $password = $admin->password;
        $this->seed(LocalDemoSeeder::class);
        $this->seed(LocalDemoSeeder::class);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('websites', 2);
        $this->assertSame($password, $admin->fresh()->password);
        $this->assertCount(2, $admin->websites);
        $this->assertSame(['pageviews', 'sessions'], Website::orderBy('tracking_mode')->pluck('tracking_mode')->all());
    }

    public function test_production_refuses_demo_data(): void
    {
        $this->app->instance('env', 'production');
        $this->expectException(\RuntimeException::class);
        $this->app->make(LocalDemoSeeder::class)->run();
    }
}
