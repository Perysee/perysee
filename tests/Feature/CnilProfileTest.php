<?php

namespace Tests\Feature;

use App\Analytics\Audit\AuditContext;
use App\Analytics\Audit\AuditProfile;
use App\Analytics\Audit\CnilExemptionThresholds;
use App\Analytics\Audit\ConfigurationAudit;
use App\Analytics\Audit\Finding;
use App\Analytics\InstanceHealth;
use App\Installation\InstallationState;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CnilProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
    }

    /** @return array<string, Finding> */
    private function findings(AuditProfile $profile): array
    {
        $found = [];
        foreach ((new ConfigurationAudit)->run(new AuditContext($profile, app(InstanceHealth::class), Request::create('https://perysee.test/health/audit'))) as $finding) {
            $found[$finding->id] = $finding;
        }

        return $found;
    }

    public function test_the_thresholds_are_named_constants_with_defaults_that_can_be_injected(): void
    {
        $default = new CnilExemptionThresholds;

        $this->assertSame(CnilExemptionThresholds::COOKIE_MAX_MONTHS, $default->cookieMaxMonths);
        $this->assertSame(CnilExemptionThresholds::DATA_MAX_MONTHS, $default->dataMaxMonths);
        $this->assertSame(CnilExemptionThresholds::CONSENT_EXEMPT_MODES, $default->consentExemptModes);
        $this->assertSame([13, 25], [CnilExemptionThresholds::COOKIE_MAX_MONTHS, CnilExemptionThresholds::DATA_MAX_MONTHS]);
        $this->assertSame(760, $default->dataMaxDays());
        $this->assertSame(13 * 2629800, $default->cookieMaxSeconds());
        $this->assertSame(1095, (new CnilExemptionThresholds(dataMaxMonths: 36))->dataMaxDays(), 'Months are turned into days with an average month.');
        $this->expectException(\InvalidArgumentException::class);
        new CnilExemptionThresholds(dataMaxMonths: -1);
    }

    public function test_statistics_kept_1095_days_give_attention_with_the_value_read(): void
    {
        $cnil = $this->findings(AuditProfile::cnil())['retention.stats'];
        $generic = $this->findings(AuditProfile::generic())['retention.stats'];

        $this->assertSame(Finding::ATTENTION, $cnil->status);
        $this->assertStringContainsString('1095 days (about 36 months)', $cnil->value);
        $this->assertStringContainsString('760 days', $cnil->threshold);
        $this->assertSame('/health#data-retention', $cnil->settingUrl, 'A link to the existing retention form, never a button that writes.');
        $this->assertSame(Finding::OK, $generic->status, 'The generic profile has no 25-month limit.');
    }

    public function test_the_retention_threshold_follows_the_injected_thresholds(): void
    {
        $this->assertSame(Finding::OK, $this->findings(AuditProfile::cnil(new CnilExemptionThresholds(dataMaxMonths: 36)))['retention.stats']->status, 'About 36 months is within 36 months.');
        $this->assertSame(Finding::ATTENTION, $this->findings(AuditProfile::cnil(new CnilExemptionThresholds(dataMaxMonths: 35)))['retention.stats']->status);

        app(InstanceHealth::class)->put('stats_retention_days', 760);
        app(InstanceHealth::class)->put('raw_retention_days', 761);
        $found = $this->findings(AuditProfile::cnil());
        $this->assertSame(Finding::OK, $found['retention.stats']->status, '760 days is the limit itself.');
        $this->assertSame(Finding::ATTENTION, $found['retention.raw']->status);
    }

    public function test_the_cookie_check_follows_the_injected_cookie_limit(): void
    {
        $this->assertSame(Finding::OK, $this->findings(AuditProfile::cnil())['measurement.cookie']->status);

        $strict = AuditProfile::cnil(new CnilExemptionThresholds(cookieMaxMonths: 0));
        $this->assertSame(Finding::OK, $this->findings($strict)['measurement.cookie']->status, 'No site sets the cookie.');

        Website::factory()->create(['tracking_mode' => 'sessions']);
        $finding = $this->findings($strict)['measurement.cookie'];
        $this->assertSame(Finding::ATTENTION, $finding->status);
        $this->assertStringContainsString('lifetime 24 h', $finding->value);
    }

    public function test_consent_may_be_waived_only_for_the_modes_of_the_thresholds(): void
    {
        Website::factory()->create(['name' => 'Counted', 'tracking_mode' => 'sessions', 'consent_required' => false]);

        $this->assertSame(Finding::OK, $this->findings(AuditProfile::cnil())['measurement.mode_consent']->status);
        $this->assertSame(Finding::ATTENTION, $this->findings(AuditProfile::generic())['measurement.mode_consent']->status);

        $narrow = AuditProfile::cnil(new CnilExemptionThresholds(consentExemptModes: ['pageviews']));
        $finding = $this->findings($narrow)['measurement.mode_consent'];
        $this->assertSame(Finding::ATTENTION, $finding->status);
        $this->assertStringContainsString('Counted', $finding->explanation);
    }

    public function test_the_profile_adds_the_cookie_scope_check_and_the_generic_one_does_not(): void
    {
        $this->assertArrayNotHasKey('measurement.cookie_scope', $this->findings(AuditProfile::generic()));
        $scope = $this->findings(AuditProfile::cnil())['measurement.cookie_scope'];

        $this->assertSame(Finding::OK, $scope->status);
        $this->assertSame(['retention.raw', 'retention.stats', 'measurement.mode_consent', 'measurement.cookie', 'measurement.cookie_scope', 'measurement.address'], array_slice(array_keys($this->findings(AuditProfile::cnil())), 0, 6));
    }

    public function test_the_generic_profile_stays_the_default_of_the_page(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get('/health/audit')->assertInertia(fn (Assert $page) => $page
            ->where('profile.id', 'generic')
            ->has('profiles', 2)
            ->where('profiles.0.id', 'generic')
            ->where('profiles.0.active', true)
            ->where('profiles.1.id', 'cnil_exemption')
            ->where('profiles.1.active', false)
            ->where('profiles.1.url', '/health/audit?profile=cnil_exemption')
            ->where('profiles.0.url', '/health/audit')
            ->has('findings', 14));
        $this->actingAs($admin)->get('/health/audit?profile=cnil')->assertInertia(fn (Assert $page) => $page->where('profile.id', 'generic'));
    }

    public function test_the_page_runs_the_cnil_profile_on_request_with_injected_thresholds(): void
    {
        $this->app->instance(CnilExemptionThresholds::class, new CnilExemptionThresholds(dataMaxMonths: 40));
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get('/health/audit?profile=cnil_exemption')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('profile.id', 'cnil_exemption')
            ->where('profile.label', 'CNIL exemption')
            ->where('profiles.1.active', true)
            ->has('findings', 15)
            ->where('findings.1.id', 'retention.stats')
            ->where('findings.1.status', 'ok')
            ->where('findings.1.threshold', fn ($threshold) => str_contains($threshold, '1217 days')));
    }

    public function test_changing_profile_has_no_side_effect(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Website::factory()->create(['tracking_mode' => 'sessions', 'consent_required' => false]);
        $genericBefore = array_map(fn (Finding $finding): array => $finding->toArray(), $this->findings(AuditProfile::generic()));
        $before = $this->dump();

        foreach (['/health/audit', '/health/audit?profile=cnil_exemption', '/health/audit', '/health/audit?profile=cnil_exemption'] as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }

        $this->assertSame(array_diff_key($before, ['user_sessions' => 1]), array_diff_key($this->dump(), ['user_sessions' => 1]));
        $this->assertSame($genericBefore, array_map(fn (Finding $finding): array => $finding->toArray(), $this->findings(AuditProfile::generic())), 'Running the CNIL profile leaves nothing behind for the generic one.');
        $this->assertSame(1095, app(InstanceHealth::class)->retentionDays('stats_retention_days'));
    }

    public function test_the_wording_states_values_and_thresholds_and_promises_nothing(): void
    {
        $banned = '/\b(compliant|compliance|conform|conforme|certified|guarantee|guaranteed|legal|lawful|legally|illegal)\b/i';
        Website::factory()->create(['tracking_mode' => 'sessions', 'consent_required' => false, 'allowed_domains' => []]);

        foreach ($this->findings(AuditProfile::cnil()) as $finding) {
            $this->assertDoesNotMatchRegularExpression($banned, implode(' ', [$finding->title, $finding->value, $finding->explanation, (string) $finding->threshold]), $finding->id);
        }
        $this->assertDoesNotMatchRegularExpression($banned, AuditProfile::cnil()->note);
    }

    /** @return array<string, string> */
    private function dump(): array
    {
        $dump = [];
        foreach (Schema::getTables() as $table) {
            $dump[$table['name']] = DB::table($table['name'])->get()->toJson();
        }

        return $dump;
    }
}
