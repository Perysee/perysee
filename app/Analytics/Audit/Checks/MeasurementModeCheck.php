<?php

declare(strict_types=1);

namespace App\Analytics\Audit\Checks;

use App\Analytics\Audit\AuditContext;
use App\Analytics\Audit\Finding;

/**
 * The measurement mode and the consent policy of each site, read in one query. The `sessions` mode is the only one
 * that sets a cookie in the visitor's browser (lueur.js); a site that does not wait for a choice before that is listed.
 */
final class MeasurementModeCheck extends AbstractCheck
{
    public function id(): string
    {
        return 'measurement.mode_consent';
    }

    public function run(AuditContext $context): Finding
    {
        ['sites' => $sites, 'truncated' => $truncated] = $context->sites();
        $exempt = $context->profile->consentExemptModes;
        $sessions = array_filter($sites, fn (array $site): bool => $site['tracking_mode'] === 'sessions');
        $withoutConsent = array_filter($sites, fn (array $site): bool => ! $site['consent_required']);
        $flagged = array_values(array_map(fn (array $site): string => $site['name'], array_filter($withoutConsent, fn (array $site): bool => ! in_array($site['tracking_mode'], $exempt, true))));

        $value = __(':sessions in sessions mode, :free without a consent requirement', ['sessions' => $this->sites(count($sessions)), 'free' => $this->sites(count($withoutConsent))])
            .($truncated ? ' '.__('(the first :count sites)', ['count' => count($sites)]) : '');

        return $this->finding(
            $flagged === [] ? Finding::OK : Finding::ATTENTION,
            __('Measurement mode and consent'),
            $value,
            $flagged === []
                ? __('Each site either asks for a choice before measuring, or uses a mode this profile treats as compatible with measuring without one.')
                : __('These sites measure without asking for a choice in a mode this profile does not treat as compatible with that: :sites.', ['sites' => $this->names($flagged)]),
            __('measuring without a choice only in: :modes', ['modes' => implode(', ', $exempt)]),
            route('sites.index', [], false),
        );
    }
}
