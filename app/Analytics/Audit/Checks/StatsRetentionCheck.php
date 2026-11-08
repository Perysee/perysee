<?php

declare(strict_types=1);

namespace App\Analytics\Audit\Checks;

use App\Analytics\Audit\AuditContext;
use App\Analytics\Audit\Finding;

/** How long the consolidated daily statistics (and the session records that follow them) are kept. */
final class StatsRetentionCheck extends AbstractCheck
{
    public function id(): string
    {
        return 'retention.stats';
    }

    public function run(AuditContext $context): Finding
    {
        $days = $context->retentionDays('stats_retention_days');
        $max = $context->profile->statsRetentionMaxDays;
        $over = $days > $max;

        return $this->finding(
            $over ? Finding::ATTENTION : Finding::OK,
            __('Statistics retention'),
            __(':days days (about :months months)', ['days' => $days, 'months' => (int) round($days / 30.4375)]),
            $over
                ? __('The daily statistics are kept longer than this profile expects. The retention form of the Instance health page changes it; shortening it deletes history for good.')
                : __('The daily statistics are deleted after this delay by the scheduled maintenance.'),
            __('at most :days days', ['days' => $max]),
            route('health', [], false),
        );
    }
}
