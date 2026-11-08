<?php

namespace Tests\Feature;

use App\Analytics\Audit\AuditContext;
use App\Analytics\Audit\AuditProfile;
use App\Analytics\Audit\Checks\AdministratorCountCheck;
use App\Analytics\Audit\ConfigurationAudit;
use App\Analytics\Audit\Finding;
use App\Analytics\InstanceHealth;
use App\Models\User;
use App\Models\UserTwoFactor;
use App\Models\Website;
use App\Support\InstanceSecurity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ConfigurationAuditChecksTest extends TestCase
{
    use RefreshDatabase;

    private ?string $storage = null;

    protected function tearDown(): void
    {
        if ($this->storage !== null) {
            File::deleteDirectory($this->storage);
        }
        parent::tearDown();
    }

    private function health(): InstanceHealth
    {
        return app(InstanceHealth::class);
    }

    /** @return array<string, Finding> */
    private function findings(string $url = 'https://perysee.test/health/audit'): array
    {
        $found = [];
        foreach ((new ConfigurationAudit)->run(new AuditContext(AuditProfile::generic(), $this->health(), Request::create($url))) as $finding) {
            $found[$finding->id] = $finding;
        }

        return $found;
    }

    private function statusOf(string $id, string $url = 'https://perysee.test/health/audit'): string
    {
        return $this->findings($url)[$id]->status;
    }

    public function test_measurement_mode_and_consent_flag_a_cookie_without_a_choice(): void
    {
        Website::factory()->create(['name' => 'Quiet', 'tracking_mode' => 'pageviews', 'consent_required' => false]);
        $this->assertSame(Finding::OK, $this->statusOf('measurement.mode_consent'), 'Pageviews set no cookie.');

        Website::factory()->create(['name' => 'Cookie', 'tracking_mode' => 'sessions', 'consent_required' => false]);
        $finding = $this->findings()['measurement.mode_consent'];
        $this->assertSame(Finding::ATTENTION, $finding->status);
        $this->assertStringContainsString('Cookie', $finding->explanation);
        $this->assertStringNotContainsString('Quiet', $finding->explanation);
        $this->assertStringContainsString('1 site in sessions mode', $finding->value);
    }

    public function test_demo_sites_and_sites_being_deleted_are_left_out(): void
    {
        Website::factory()->create(['tracking_mode' => 'sessions', 'consent_required' => false, 'allowed_domains' => []]);
        DB::table('websites')->update(['is_demo' => true]);
        Website::factory()->create(['allowed_domains' => []]);
        DB::table('websites')->where('is_demo', false)->update(['deleting_at' => now('UTC')]);

        $found = $this->findings();

        $this->assertSame(Finding::OK, $found['measurement.mode_consent']->status);
        $this->assertSame(Finding::OK, $found['sites.allowed_domains']->status);
    }

    public function test_the_cookie_values_are_the_ones_of_the_configuration(): void
    {
        $finding = $this->findings()['measurement.cookie'];

        $this->assertStringContainsString('Max-Age 30 min', $finding->value);
        $this->assertStringContainsString('inactivity 30 min', $finding->value);
        $this->assertStringContainsString('lifetime 24 h', $finding->value);
        $this->assertSame(Finding::OK, $finding->status);
        $this->assertNull($finding->threshold, 'The generic profile sets no cookie limit.');

        config(['perysee.tracker.cookie_max_age_seconds' => 600, 'perysee.tracker.session_idle_ms' => 3600000]);
        $this->assertStringContainsString('Max-Age 10 min', $this->findings()['measurement.cookie']->value);
        $this->assertStringContainsString('inactivity 60 min', $this->findings()['measurement.cookie']->value);
    }

    public function test_allowed_domains_flag_an_empty_list_and_a_wildcard(): void
    {
        Website::factory()->create(['name' => 'Fine']);
        $this->assertSame(Finding::OK, $this->statusOf('sites.allowed_domains'));

        Website::factory()->create(['name' => 'Joker', 'allowed_domains' => ['*.example.org']]);
        $this->assertSame(Finding::ATTENTION, $this->statusOf('sites.allowed_domains'));

        Website::factory()->create(['name' => 'Nobody', 'allowed_domains' => []]);
        $finding = $this->findings()['sites.allowed_domains'];
        $this->assertSame(Finding::PROBLEM, $finding->status);
        $this->assertStringContainsString('Nobody', $finding->explanation);
    }

    public function test_https_needs_the_configured_address_and_the_request(): void
    {
        config(['app.url' => 'https://perysee.test']);
        $this->assertSame(Finding::OK, $this->statusOf('instance.https'));
        $this->assertSame(Finding::ATTENTION, $this->statusOf('instance.https', 'http://perysee.test/health/audit'));

        config(['app.url' => 'http://perysee.test']);
        $this->assertSame(Finding::ATTENTION, $this->statusOf('instance.https'));
        $this->assertSame(Finding::ATTENTION, $this->statusOf('instance.https', 'http://perysee.test/health/audit'), 'Not production: attention only.');

        $this->app['env'] = 'production';
        $this->assertSame(Finding::PROBLEM, $this->statusOf('instance.https', 'http://perysee.test/health/audit'));
    }

    public function test_the_scheduler_follows_the_heartbeat_and_the_backlog(): void
    {
        $this->assertSame(Finding::PROBLEM, $this->statusOf('instance.scheduler'), 'Never ran.');

        $this->health()->put('heartbeat', now('UTC')->toIso8601String());
        $this->assertSame(Finding::OK, $this->statusOf('instance.scheduler'));

        $site = Website::factory()->create();
        DB::table('events')->insert(['website_id' => $site->id, 'received_at' => now('UTC')->subMinutes(40), 'event_type' => 1, 'path' => '/', 'consent_given' => true]);
        $this->assertSame(Finding::ATTENTION, $this->statusOf('instance.scheduler'));

        $this->health()->put('heartbeat', now('UTC')->subMinutes(30)->toIso8601String());
        $this->assertSame(Finding::PROBLEM, $this->statusOf('instance.scheduler'));
    }

    public function test_the_backup_is_read_from_the_updater_folder_only(): void
    {
        $this->storage = sys_get_temp_dir().'/perysee-audit-'.bin2hex(random_bytes(6));
        mkdir($this->storage, 0700);
        $this->app->useStoragePath($this->storage);

        $none = $this->findings()['instance.backup'];
        $this->assertSame(Finding::ATTENTION, $none->status);
        $this->assertStringContainsString('no backup made by the updater', $none->value);
        $this->assertStringContainsString('says nothing about those', $none->explanation);

        $this->backup('20260101-000000-v0.3.0', now('UTC')->subDays(90));
        $this->assertSame(Finding::ATTENTION, $this->statusOf('instance.backup'), 'Too old.');

        $this->backup('20261101-000000-v0.4.0', now('UTC')->subDays(7));
        $recent = $this->findings()['instance.backup'];
        $this->assertSame(Finding::OK, $recent->status);
        $this->assertStringContainsString('7 days ago', $recent->value);

        mkdir($this->storage.'/app/private/updates/backups/20261105-000000-v0.4.0', 0700, true);
        $this->assertSame(Finding::OK, $this->statusOf('instance.backup'), 'A folder without its record is skipped.');
    }

    private function backup(string $folder, \DateTimeInterface $at): void
    {
        $path = $this->storage.'/app/private/updates/backups/'.$folder;
        mkdir($path, 0700, true);
        file_put_contents($path.'/backup.json', json_encode(['created_at' => $at->format(DATE_ATOM)]));
    }

    public function test_the_version_reads_the_stored_check_and_never_the_network(): void
    {
        Http::fake();
        config(['perysee.version' => '0.4.0', 'perysee.update.check' => true]);
        $this->assertSame(Finding::ATTENTION, $this->statusOf('instance.version'), 'No check recorded yet.');

        $this->health()->put('update_check', ['checked_at' => '2026-11-01T10:00:00+00:00', 'latest' => ['tag' => 'v0.4.0'], 'error' => null]);
        $this->assertSame(Finding::OK, $this->statusOf('instance.version'));

        $this->health()->put('update_check', ['checked_at' => '2026-11-01T10:00:00+00:00', 'latest' => ['tag' => 'v0.5.1'], 'error' => null]);
        $newer = $this->findings()['instance.version'];
        $this->assertSame(Finding::ATTENTION, $newer->status);
        $this->assertStringContainsString('0.5.1 available (checked 2026-11-01)', $newer->value);

        config(['perysee.update.check' => false]);
        $this->assertStringContainsString('turned off', $this->findings()['instance.version']->value);

        Http::assertNothingSent();
    }

    public function test_the_storage_budget_and_the_tier(): void
    {
        $this->assertSame(Finding::OK, $this->statusOf('instance.storage'), 'No budget.');

        $this->health()->put('storage_budget_bytes', 1000);
        $this->health()->put('storage_mode', 'auto');
        $this->health()->put('storage_budget_state', ['bytes' => 500, 'ratio_percent' => 50, 'checked_at' => now('UTC')->toIso8601String()]);
        $this->assertSame(Finding::OK, $this->statusOf('instance.storage'));

        $this->health()->put('storage_budget_state', ['bytes' => 1200, 'ratio_percent' => 120, 'checked_at' => now('UTC')->toIso8601String()]);
        $this->assertSame(Finding::ATTENTION, $this->statusOf('instance.storage'));

        $this->health()->put('storage_tier', 2);
        $finding = $this->findings()['instance.storage'];
        $this->assertSame(Finding::ATTENTION, $finding->status);
        $this->assertStringContainsString('tier 2', $finding->value);
    }

    public function test_two_factor_counts_administrators_without_a_confirmed_second_factor(): void
    {
        $protected = User::factory()->create(['is_admin' => true]);
        UserTwoFactor::create(['user_id' => $protected->id, 'secret' => 'ABCDEFGHIJKLMNOP', 'confirmed_at' => now('UTC'), 'created_at' => now('UTC')]);
        $this->assertSame(Finding::OK, $this->statusOf('access.two_factor'));

        $pending = User::factory()->create(['is_admin' => true]);
        UserTwoFactor::create(['user_id' => $pending->id, 'secret' => 'ABCDEFGHIJKLMNOP', 'created_at' => now('UTC')]);
        User::factory()->create(['is_admin' => false]);
        $finding = $this->findings()['access.two_factor'];
        $this->assertSame(Finding::ATTENTION, $finding->status);
        $this->assertStringContainsString('1 of 2 administrators without', $finding->value);

        app(InstanceSecurity::class)->requireTwoFactor(true);
        $this->assertSame(Finding::OK, $this->statusOf('access.two_factor'));
        Cache::flush();
    }

    public function test_the_number_of_administrators(): void
    {
        User::factory()->count(AdministratorCountCheck::MAX)->create(['is_admin' => true]);
        $this->assertSame(Finding::OK, $this->statusOf('access.administrators'));

        User::factory()->create(['is_admin' => true]);
        $this->assertSame(Finding::ATTENTION, $this->statusOf('access.administrators'));
    }

    public function test_continuity_breaks_of_the_last_ninety_days_only(): void
    {
        $this->assertSame(Finding::OK, $this->statusOf('history.continuity'));

        foreach ([['breaks', 120], ['note', 2]] as [$severity, $days]) {
            DB::table('continuity_log')->insert(['website_id' => null, 'occurred_at' => now('UTC')->subDays($days)->format('Y-m-d H:i:s.v'), 'kind' => 'retention_change', 'severity' => $severity, 'scope' => 'all', 'payload' => '{}']);
        }
        $this->assertSame(Finding::OK, $this->statusOf('history.continuity'), 'An old break and a note do not count.');

        DB::table('continuity_log')->insert(['website_id' => null, 'occurred_at' => now('UTC')->subDays(10)->format('Y-m-d H:i:s.v'), 'kind' => 'storage_tier', 'severity' => 'breaks', 'scope' => 'all', 'payload' => '{}']);
        $finding = $this->findings()['history.continuity'];
        $this->assertSame(Finding::ATTENTION, $finding->status);
        $this->assertStringContainsString('1 in the last 90 days', $finding->value);
        $this->assertStringContainsString('storage_tier', $finding->value);
    }

    public function test_every_finding_states_a_value_and_an_explanation(): void
    {
        foreach ($this->findings() as $finding) {
            $this->assertNotSame('', $finding->value, $finding->id);
            $this->assertNotSame('', $finding->explanation, $finding->id);
            $this->assertContains($finding->status, [Finding::OK, Finding::ATTENTION, Finding::PROBLEM], $finding->id);
            $this->assertMatchesRegularExpression('/^[a-z]+-[a-z_]+$/', $finding->slug);
        }
    }
}
