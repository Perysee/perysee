<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Website;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiTokenPurgeTest extends TestCase
{
    use RefreshDatabase;

    private function token(Website $site, array $attributes = []): ApiToken
    {
        [$token] = ApiToken::issue($site, null, 'x', ['overview'], 60, $attributes['expires_at'] ?? null);
        if (isset($attributes['revoked_at'])) {
            $token->update(['revoked_at' => $attributes['revoked_at']]);
        }

        return $token;
    }

    public function test_tokens_ended_for_more_than_thirty_days_are_deleted_and_the_others_kept(): void
    {
        $site = Website::factory()->create();
        $expiredLong = $this->token($site, ['expires_at' => now()->subDays(31)]);
        $revokedLong = $this->token($site, ['revoked_at' => now()->subDays(45)]);
        $expiredRecent = $this->token($site, ['expires_at' => now()->subDays(29)]);
        $revokedRecent = $this->token($site, ['revoked_at' => now()->subDays(2)]);
        $active = $this->token($site);
        $future = $this->token($site, ['expires_at' => now()->addDays(3)]);

        $this->artisan('perysee:prune-api-tokens')->assertSuccessful();

        $this->assertNull(ApiToken::find($expiredLong->id));
        $this->assertNull(ApiToken::find($revokedLong->id));
        foreach ([$expiredRecent, $revokedRecent, $active, $future] as $kept) {
            $this->assertNotNull(ApiToken::find($kept->id));
        }
    }

    public function test_the_purge_works_in_batches(): void
    {
        $site = Website::factory()->create();
        foreach (range(1, 7) as $unused) {
            $this->token($site, ['revoked_at' => now()->subDays(60)]);
        }
        $keep = $this->token($site);
        $this->artisan('perysee:prune-api-tokens', ['--limit' => 3])->assertSuccessful();
        $this->assertSame(5, ApiToken::count());
        $this->artisan('perysee:prune-api-tokens', ['--limit' => 100])->assertSuccessful();
        $this->assertSame([$keep->id], ApiToken::pluck('id')->all());
    }

    public function test_it_is_a_dedicated_hourly_command_that_does_not_overlap(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command, 'perysee:prune-api-tokens'));
        $this->assertNotNull($event);
        $this->assertSame('0 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $maintenance = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command, 'perysee:maintenance'));
        $this->assertStringNotContainsString('api-tokens', $maintenance->command);
    }
}
