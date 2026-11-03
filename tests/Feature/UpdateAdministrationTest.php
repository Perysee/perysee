<?php

namespace Tests\Feature;

use App\Analytics\InstanceHealth;
use App\Installation\InstallationState;
use App\Models\User;
use App\Updates\ReleaseFeed;
use App\Updates\UpdateStatus;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class UpdateAdministrationTest extends TestCase
{
    use RefreshDatabase;

    private function release(string $tag = 'v9.9.9', bool $draft = false): array
    {
        return ['tag_name' => $tag, 'draft' => $draft, 'published_at' => '2026-11-04T10:00:00Z', 'html_url' => "https://github.com/Perysee/app/releases/tag/{$tag}", 'assets' => [
            ['name' => 'perysee.zip', 'browser_download_url' => 'https://github.com/Perysee/app/releases/download/'.$tag.'/perysee.zip'],
            ['name' => 'SHA256SUMS', 'browser_download_url' => 'https://github.com/Perysee/app/releases/download/'.$tag.'/SHA256SUMS'],
        ]];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->make(InstallationState::class)->exclusive(fn () => $this->app->make(InstallationState::class)->complete());
    }

    /** Replaces whatever GitHub answered before: the first matching fake of a factory would win. */
    private function github(array|string $body, int $status = 200): void
    {
        $this->app->forgetInstance(Factory::class);
        Http::clearResolvedInstance(Factory::class);
        Http::fake(['api.github.com/*' => Http::response($body, $status)]);
    }

    /** A second actingAs in one test keeps the guard and the session password hash of the first user, so both are reset first. */
    private function asAdmin(): static
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        return $this->actingAs($this->admin());
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true, 'password' => 'a-long-enough-password']);
    }

    public function test_the_feed_remembers_the_latest_release_and_offers_it_only_when_newer(): void
    {
        $this->github($this->release());
        $feed = $this->app->make(ReleaseFeed::class);

        $state = $feed->refresh();

        $this->assertNull($state['error']);
        $this->assertSame('9.9.9', $feed->available()?->version());
        config(['perysee.version' => '9.9.9']);
        $this->assertNull($feed->available());
        Http::assertSent(fn ($request) => $request->url() === 'https://api.github.com/repos/Perysee/app/releases/latest' && str_starts_with($request->header('User-Agent')[0], 'Perysee/'));
    }

    public function test_a_release_without_its_archive_and_checksums_does_not_exist_for_instances(): void
    {
        $incomplete = $this->release();
        $incomplete['assets'] = [$incomplete['assets'][0]];
        $this->github($incomplete);

        $this->assertNull($this->app->make(ReleaseFeed::class)->latest());
    }

    public function test_a_failed_check_keeps_the_previous_answer_and_records_the_reason(): void
    {
        $feed = $this->app->make(ReleaseFeed::class);
        $this->github($this->release());
        $feed->refresh();
        $this->github('', 403);

        $state = $feed->refresh();

        $this->assertStringContainsString('HTTP 403', $state['error']);
        $this->assertSame('9.9.9', $feed->available()?->version());
        $this->assertSame($state['error'], $feed->stored()['error']);
    }

    public function test_the_health_page_shows_the_update_state_to_administrators_only(): void
    {
        $this->github($this->release());
        $this->app->make(ReleaseFeed::class)->refresh();

        $this->actingAs(User::factory()->create())->get('/health')->assertForbidden();
        $this->asAdmin()->get('/health')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('softwareUpdate.current', config('perysee.version'))
            ->where('softwareUpdate.available.tag', 'v9.9.9')
            ->where('softwareUpdate.status.state', 'idle')
            ->has('softwareUpdate.methods', 2));
    }

    public function test_checking_for_updates_is_admin_only_and_reports_failures(): void
    {
        $this->github($this->release());

        $this->actingAs(User::factory()->create())->post('/health/updates/check')->assertForbidden();
        $this->asAdmin()->post('/health/updates/check')->assertSessionHas('status', 'A new version is available.');

        $this->github('', 500);
        $this->post('/health/updates/check')->assertSessionHasErrors('update');
    }

    public function test_an_update_request_needs_the_password_a_newer_release_and_a_running_scheduler(): void
    {
        $this->github($this->release());
        $admin = $this->admin();
        $this->actingAs($admin);
        $health = $this->app->make(InstanceHealth::class);

        $this->post('/health/updates', ['password' => 'a-long-enough-password', 'dump' => 'auto'])->assertSessionHasErrors('update');

        $this->app->make(ReleaseFeed::class)->refresh();
        $this->post('/health/updates', ['password' => 'wrong', 'dump' => 'auto'])->assertSessionHasErrors('password');
        $this->post('/health/updates', ['password' => 'a-long-enough-password', 'dump' => 'external'])->assertSessionHasErrors('dump');
        $this->post('/health/updates', ['password' => 'a-long-enough-password', 'dump' => 'auto'])->assertSessionHasErrors('update');
        $this->assertNull($this->app->make(UpdateStatus::class)->requested());

        $health->put('heartbeat', now('UTC')->toIso8601String());
        $this->post('/health/updates', ['password' => 'a-long-enough-password', 'dump' => 'php'])->assertSessionHasNoErrors()->assertSessionHas('status');

        $request = $this->app->make(UpdateStatus::class)->requested();
        $this->assertSame('v9.9.9', $request['tag']);
        $this->assertSame('php', $request['dump']);
        $this->assertSame($admin->id, $request['user']);
    }

    public function test_a_request_can_be_cancelled_and_nobody_else_can_request_or_cancel(): void
    {
        $status = $this->app->make(UpdateStatus::class);
        $status->request('v9.9.9', 'auto', 1);

        $this->actingAs(User::factory()->create())->delete('/health/updates')->assertForbidden();
        $this->assertNotNull($status->requested());
        $this->asAdmin()->delete('/health/updates')->assertRedirect();
        $this->assertNull($status->requested());
    }

    public function test_no_request_is_accepted_while_an_update_needs_attention(): void
    {
        $this->github($this->release());
        $this->app->make(ReleaseFeed::class)->refresh();
        $this->app->make(InstanceHealth::class)->put('heartbeat', now('UTC')->toIso8601String());
        $this->app->make(UpdateStatus::class)->put('needs_attention', ['message' => 'Restore the backup']);

        $this->asAdmin()->post('/health/updates', ['password' => 'a-long-enough-password', 'dump' => 'auto'])->assertSessionHasErrors('update');
        $this->assertNull($this->app->make(UpdateStatus::class)->requested());
    }

    public function test_the_check_command_reports_and_fails_on_a_network_error(): void
    {
        $this->github($this->release());
        $this->artisan('perysee:update:check')->expectsOutputToContain('Version 9.9.9 is available')->assertSuccessful();

        $this->github('', 503);
        $this->artisan('perysee:update:check')->assertFailed();
    }

    public function test_the_check_command_does_nothing_when_checks_are_disabled(): void
    {
        config(['perysee.update.check' => false]);
        Http::fake();

        $this->artisan('perysee:update:check')->expectsOutputToContain('disabled')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_the_scheduled_run_without_a_request_does_nothing_and_costs_one_query(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->artisan('perysee:update --requested --yes')->assertSuccessful();

        $this->assertCount(1, $queries, implode(' | ', $queries));
    }

    public function test_the_update_command_refuses_an_unknown_dump_method(): void
    {
        $this->artisan('perysee:update --dump=magic --yes')->expectsOutputToContain('--dump=auto')->assertFailed();
    }

    public function test_finalizing_refuses_files_of_another_version(): void
    {
        $this->artisan('perysee:update:finalize --expect=0.0.1')->assertFailed();
        $this->artisan('perysee:update:finalize --expect=v'.config('perysee.version'))->assertSuccessful();
    }

    public function test_the_scheduler_runs_the_requested_update_every_minute_and_the_check_daily(): void
    {
        $events = collect($this->app->make(Schedule::class)->events());
        $commands = $events->mapWithKeys(fn ($event): array => [$event->command => $event->expression]);

        $this->assertSame('* * * * *', $commands->first(fn ($expression, $command): bool => str_contains($command, 'perysee:update --requested')));
        $this->assertSame('17 4 * * *', $commands->first(fn ($expression, $command): bool => str_contains($command, 'perysee:update:check')));
    }
}
