<?php

namespace Tests\Feature;

use App\Installation\InstallationState;
use App\Models\User;
use App\Support\CliPhp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HealthCronTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_cron_line_shown_to_administrators_uses_the_cli_php_not_the_web_one(): void
    {
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
        $this->app->instance(CliPhp::class, new CliPhp('litespeed', '/usr/local/bin/lsphp', '/opt/alt/php83/usr/bin', '8.3', fn (string $path): bool => $path === '/opt/alt/php83/usr/bin/php', false));
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get('/health')->assertInertia(fn (Assert $page) => $page
            ->where('cron', fn (string $line) => str_ends_with($line, "&& '/opt/alt/php83/usr/bin/php' artisan schedule:run > /dev/null 2>> storage/logs/cron.log")
                && str_starts_with($line, '* * * * * cd ')
                && ! str_contains($line, 'lsphp')));
    }
}
