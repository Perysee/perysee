<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Analytics\Audit\AuditContext;
use App\Analytics\Audit\AuditProfile;
use App\Analytics\Audit\CnilExemptionThresholds;
use App\Analytics\Audit\ConfigurationAudit;
use App\Analytics\Audit\Finding;
use App\Analytics\InstanceHealth;
use App\Support\Ui;
use Illuminate\Http\Request;
use Inertia\Response;

/** Administrators: what the current settings imply, read when the page opens and never changed by it (ADR 009). */
class ConfigurationAuditController extends Controller
{
    /** Where the documentation explains each check; the finding's slug is the anchor. */
    public const DOCS_PATH = '/administration/configuration-audit';

    public function show(Request $request, ConfigurationAudit $audit, InstanceHealth $health, CnilExemptionThresholds $cnil): Response
    {
        abort_unless($request->user()->is_admin, 403);
        // The profile only changes the thresholds of this reading; nothing is stored. The generic one is the default.
        $profile = AuditProfile::fromId($request->query('profile'), $cnil);
        $findings = array_map(fn (Finding $finding): array => $finding->toArray(), $audit->run(new AuditContext($profile, $health, $request)));

        return Ui::render('health.audit', [
            'title' => __('Configuration audit'),
            'profile' => ['id' => $profile->id, 'label' => $profile->label, 'note' => $profile->note],
            'profiles' => array_map(fn (AuditProfile $candidate): array => ['id' => $candidate->id, 'label' => $candidate->label, 'url' => route('health.audit', $candidate->id === AuditProfile::GENERIC ? [] : ['profile' => $candidate->id], false), 'active' => $candidate->id === $profile->id], AuditProfile::all($cnil)),
            'categories' => [
                ['id' => 'retention', 'label' => __('Data retention')],
                ['id' => 'measurement', 'label' => __('Measurement')],
                ['id' => 'sites', 'label' => __('Sites')],
                ['id' => 'instance', 'label' => __('Instance')],
                ['id' => 'access', 'label' => __('Access')],
                ['id' => 'history', 'label' => __('History')],
            ],
            'findings' => $findings,
            'counts' => array_count_values(array_column($findings, 'status')) + array_fill_keys(Finding::STATUSES, 0),
            'docsPath' => self::DOCS_PATH,
            'healthUrl' => route('health', [], false),
        ]);
    }
}
