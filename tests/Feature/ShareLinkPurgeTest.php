<?php

namespace Tests\Feature;

use App\Models\Website;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ShareLinkPurgeTest extends TestCase
{
    use RefreshDatabase;

    private function link(Website $site, ?int $expiredDaysAgo = null, ?int $revokedDaysAgo = null, ?int $expiresInDays = null): int
    {
        return DB::table('share_links')->insertGetId([
            'website_id' => $site->id, 'token_hash' => hash('sha256', uniqid('', true)), 'token_hint' => 'abcdef', 'sections' => '[]',
            'expires_at' => $expiredDaysAgo !== null ? now()->subDays($expiredDaysAgo) : ($expiresInDays !== null ? now()->addDays($expiresInDays) : null),
            'revoked_at' => $revokedDaysAgo !== null ? now()->subDays($revokedDaysAgo) : null,
            'created_at' => now()->subDays(100), 'updated_at' => now()->subDays(100),
        ]);
    }

    public function test_links_ended_for_more_than_thirty_days_are_deleted_and_the_others_kept(): void
    {
        $site = Website::factory()->create();
        $this->link($site, expiredDaysAgo: 31);
        $this->link($site, revokedDaysAgo: 45);
        $kept = [
            $this->link($site, expiredDaysAgo: 29), $this->link($site, revokedDaysAgo: 2),
            $this->link($site), $this->link($site, expiresInDays: 3),
        ];

        $this->artisan('perysee:prune-share-links')->assertSuccessful();

        $this->assertEqualsCanonicalizing($kept, DB::table('share_links')->pluck('id')->all());
    }

    public function test_the_purge_works_in_several_batches_and_a_run_is_capped(): void
    {
        $site = Website::factory()->create();
        foreach (range(1, 45) as $unused) {
            $this->link($site, revokedDaysAgo: 60);
        }
        $keep = $this->link($site);

        $this->artisan('perysee:prune-share-links', ['--limit' => 2])->assertSuccessful();
        $this->assertSame(6, DB::table('share_links')->count(), '20 batches of 2 per run.');

        $this->artisan('perysee:prune-share-links', ['--limit' => 2])->assertSuccessful();
        $this->assertSame([$keep], DB::table('share_links')->pluck('id')->all());
    }

    public function test_an_invalid_limit_is_refused(): void
    {
        $this->artisan('perysee:prune-share-links', ['--limit' => 0])->assertFailed();
        $this->artisan('perysee:prune-share-links', ['--limit' => 10001])->assertFailed();
    }

    public function test_an_idle_pass_costs_one_read_and_no_write(): void
    {
        $site = Website::factory()->create();
        $this->link($site, expiresInDays: 3);
        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $this->artisan('perysee:prune-share-links')->assertSuccessful();

        $this->assertCount(1, $statements, implode(' | ', $statements));
    }

    public function test_it_is_a_dedicated_hourly_command_that_does_not_overlap(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command, 'perysee:prune-share-links'));
        $this->assertNotNull($event);
        $this->assertSame('0 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }
}
