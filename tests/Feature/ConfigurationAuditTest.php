<?php

namespace Tests\Feature;

use App\Analytics\Audit\AuditContext;
use App\Analytics\Audit\AuditProfile;
use App\Analytics\Audit\Check;
use App\Analytics\Audit\ConfigurationAudit;
use App\Analytics\Audit\Finding;
use App\Analytics\InstanceHealth;
use App\Installation\InstallationState;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ConfigurationAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
    }

    /** @return array<string, Finding> */
    private function findings(?AuditProfile $profile = null, ?ConfigurationAudit $audit = null): array
    {
        $context = new AuditContext($profile ?? AuditProfile::generic(), app(InstanceHealth::class), Request::create('https://perysee.test/health/audit'));
        $found = [];
        foreach (($audit ?? new ConfigurationAudit)->run($context) as $finding) {
            $found[$finding->id] = $finding;
        }

        return $found;
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_retention_check_reports_attention_above_threshold(): void
    {
        $health = app(InstanceHealth::class);
        $stats = AuditProfile::GENERIC_STATS_MAX_DAYS;
        $raw = AuditProfile::GENERIC_RAW_MAX_DAYS;

        $health->put('stats_retention_days', $stats);
        $health->put('raw_retention_days', $raw);
        $found = $this->findings();
        $this->assertSame(Finding::OK, $found['retention.stats']->status, 'The threshold itself is allowed.');
        $this->assertSame(Finding::OK, $found['retention.raw']->status);

        $health->put('stats_retention_days', $stats + 1);
        $health->put('raw_retention_days', $raw + 1);
        $found = $this->findings();
        $this->assertSame(Finding::ATTENTION, $found['retention.stats']->status);
        $this->assertSame(Finding::ATTENTION, $found['retention.raw']->status);
        $this->assertStringContainsString((string) ($stats + 1), $found['retention.stats']->value);
        $this->assertStringContainsString((string) $stats, $found['retention.stats']->threshold);
    }

    public function test_an_instance_that_saved_nothing_reads_the_defaults(): void
    {
        $found = $this->findings();

        $this->assertStringContainsString('90 days', $found['retention.raw']->value);
        $this->assertStringContainsString('1095 days', $found['retention.stats']->value);
        $this->assertSame(Finding::OK, $found['retention.stats']->status);
    }

    public function test_the_generic_profile_is_the_default_and_unknown_names_fall_back_to_it(): void
    {
        $this->assertSame('generic', AuditProfile::fromId(null)->id);
        $this->assertSame('generic', AuditProfile::fromId('nothing')->id);
        $this->assertSame('generic', AuditProfile::fromId(['x'])->id);
        $this->assertSame('generic', AuditProfile::all()[0]->id);
    }

    public function test_the_registry_has_unique_ids_with_a_known_category_in_order(): void
    {
        $ids = array_map(fn (Check $check): string => $check->id(), (new ConfigurationAudit)->checks(AuditProfile::generic()));

        $this->assertSame($ids, array_values(array_unique($ids)));
        $this->assertSame(['retention.raw', 'retention.stats', 'measurement.mode_consent', 'measurement.cookie', 'measurement.address', 'sites.allowed_domains', 'instance.https', 'instance.scheduler', 'instance.backup', 'instance.version', 'instance.storage', 'access.two_factor', 'access.administrators', 'history.continuity'], $ids);
    }

    public function test_a_check_that_throws_becomes_an_unavailable_finding_and_the_others_still_run(): void
    {
        $broken = new class implements Check
        {
            public function id(): string
            {
                return 'instance.broken';
            }

            public function run(AuditContext $context): Finding
            {
                throw new \RuntimeException('SQLSTATE secret detail');
            }
        };
        $fine = new class implements Check
        {
            public function id(): string
            {
                return 'instance.fine';
            }

            public function run(AuditContext $context): Finding
            {
                return new Finding($this->id(), 'Fine', Finding::OK, '1', 'Fine.');
            }
        };

        $found = $this->findings(audit: new ConfigurationAudit([$broken, $fine]));

        $this->assertSame(Finding::UNAVAILABLE, $found['instance.broken']->status);
        $this->assertStringNotContainsString('secret', json_encode($found['instance.broken']->toArray()));
        $this->assertSame(Finding::OK, $found['instance.fine']->status);
    }

    public function test_a_failing_check_does_not_break_the_page(): void
    {
        $broken = new class implements Check
        {
            public function id(): string
            {
                return 'instance.broken';
            }

            public function run(AuditContext $context): Finding
            {
                throw new \RuntimeException('boom');
            }
        };
        $this->app->instance(ConfigurationAudit::class, new ConfigurationAudit([$broken]));

        $this->actingAs($this->admin())->get('/health/audit')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('findings.0.id', 'instance.broken')
            ->where('findings.0.status', 'unavailable')
            ->where('counts.unavailable', 1));
    }

    public function test_the_page_is_for_administrators_only(): void
    {
        $this->get('/health/audit')->assertRedirect('/login');
        $this->actingAs(User::factory()->create(['is_admin' => false]))->get('/health/audit')->assertForbidden();
        $this->actingAs($this->admin())->get('/health/audit')->assertOk();
    }

    public function test_the_page_has_no_write_route(): void
    {
        $methods = collect(app('router')->getRoutes()->getRoutes())->filter(fn ($route) => str_starts_with($route->uri(), 'health/audit'))->flatMap(fn ($route) => $route->methods())->unique()->sort()->values()->all();

        $this->assertSame(['GET', 'HEAD'], $methods);
        $this->actingAs($this->admin())->post('/health/audit')->assertStatus(405);
    }

    public function test_the_page_writes_nothing(): void
    {
        $admin = $this->admin();
        User::factory()->create(['is_admin' => true]);
        Website::factory()->create(['allowed_domains' => []]);
        Website::factory()->create(['tracking_mode' => 'sessions', 'consent_required' => false]);
        app(InstanceHealth::class)->put('stats_retention_days', 4000);
        DB::table('continuity_log')->insert(['website_id' => null, 'occurred_at' => now('UTC')->format('Y-m-d H:i:s.v'), 'kind' => 'retention_change', 'severity' => 'breaks', 'scope' => 'all', 'payload' => '{}']);
        $before = $this->dump();
        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (! preg_match('/^\s*select\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        $this->actingAs($admin)->get('/health/audit?profile=anything')->assertOk();
        $this->actingAs($admin)->get('/health/audit')->assertOk();

        // Any signed-in request records its device in `user_sessions`: that is the shell of every page, not the audit.
        $this->assertSame([], array_values(array_filter($writes, fn (string $sql): bool => ! str_contains($sql, '"user_sessions"'))), 'Only the device list may be written.');
        $this->assertSame(array_diff_key($before, ['user_sessions' => 1]), array_diff_key($this->dump(), ['user_sessions' => 1]));
    }

    public function test_the_page_costs_a_bounded_number_of_queries(): void
    {
        foreach (range(1, 12) as $i) {
            Website::factory()->create();
        }
        User::factory()->count(3)->create(['is_admin' => true]);
        $admin = $this->admin();
        // With a live heartbeat the scheduler check goes on to read the backlog and the quota: the dearest case.
        app(InstanceHealth::class)->put('heartbeat', now('UTC')->toIso8601String());
        $this->actingAs($admin)->get('/health/audit')->assertOk();

        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });
        $this->actingAs($admin)->get('/health/audit')->assertOk();

        $count = fn (string $needle): int => count(array_filter($statements, fn (string $sql): bool => str_contains($sql, $needle)));
        $this->assertSame(1, $count('from "continuity_log"'), 'One read of the log.');
        $this->assertSame(1, $count('"user_two_factor"'), 'One read for all the administrators.');
        $this->assertSame(1, $count('"websites" where "is_demo"'), 'One read for all the sites, whatever their number.');
        $this->assertLessThanOrEqual(13, count($statements), implode("\n", $statements));
    }

    public function test_the_page_never_calls_out_of_the_server(): void
    {
        Http::fake();
        app(InstanceHealth::class)->put('update_check', ['checked_at' => now('UTC')->toIso8601String(), 'latest' => ['tag' => 'v9.9.9'], 'error' => null]);

        $this->actingAs($this->admin())->get('/health/audit')->assertOk();

        Http::assertNothingSent();
    }

    public function test_the_inertia_props(): void
    {
        $this->actingAs($this->admin())->get('/health/audit')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Workspace')
            ->where('screen', 'health.audit')
            ->where('profile.id', 'generic')
            ->has('profiles', 1)
            ->where('profiles.0.active', true)
            ->has('categories', 6)
            ->has('findings', 14)
            ->has('findings.0', fn (Assert $finding) => $finding->hasAll(['id', 'category', 'title', 'status', 'value', 'threshold', 'explanation', 'slug', 'setting_url']))
            ->where('counts.ok', fn ($ok) => is_int($ok))
            ->where('healthUrl', '/health')
            ->where('links', fn ($links) => ($links['health.audit'] ?? null) === '/health/audit'));
    }

    public function test_every_screen_shares_the_link_the_health_page_and_the_menu_use(): void
    {
        $this->actingAs($this->admin())->get('/health')->assertInertia(fn (Assert $page) => $page->where('links', fn ($links) => ($links['health.audit'] ?? null) === '/health/audit'));
    }

    /** @return array<string, string> Every table of the database, serialised. */
    private function dump(): array
    {
        $dump = [];
        foreach (Schema::getTables() as $table) {
            $dump[$table['name']] = DB::table($table['name'])->get()->toJson();
        }

        return $dump;
    }
}
