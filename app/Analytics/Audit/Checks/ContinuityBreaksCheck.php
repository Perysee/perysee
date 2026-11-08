<?php

declare(strict_types=1);

namespace App\Analytics\Audit\Checks;

use App\Analytics\Audit\AuditContext;
use App\Analytics\Audit\Finding;

/** The continuity breaks of the last 90 days (ADR 009): changes after which figures are no longer comparable as they are. */
final class ContinuityBreaksCheck extends AbstractCheck
{
    public function id(): string
    {
        return 'history.continuity';
    }

    public function run(AuditContext $context): Finding
    {
        $breaks = $context->recentBreaks();
        $count = count($breaks);
        $latest = $breaks[0] ?? null;

        return $this->finding(
            $count === 0 ? Finding::OK : Finding::ATTENTION,
            __('Continuity breaks'),
            $latest === null
                ? __('none in the last :days days', ['days' => AuditContext::BREAKS_WINDOW_DAYS])
                : __(':count in the last :days days, the latest on :date (:kind)', ['count' => $count >= AuditContext::MAX_BREAKS ? $count.'+' : $count, 'days' => AuditContext::BREAKS_WINDOW_DAYS, 'date' => substr($latest['occurred_at'], 0, 10), 'kind' => $latest['kind']]),
            $count === 0
                ? __('No setting that changes how figures are measured was changed in this period.')
                : __('A setting changed in a way that makes figures before and after it hard to compare. The reports mark these changes and hold back the percentages across them.'),
            __('no break in the last :days days', ['days' => AuditContext::BREAKS_WINDOW_DAYS]),
        );
    }
}
