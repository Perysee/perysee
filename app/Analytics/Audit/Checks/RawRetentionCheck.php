<?php

declare(strict_types=1);

namespace App\Analytics\Audit\Checks;

use App\Analytics\Audit\AuditContext;
use App\Analytics\Audit\Finding;

/** How long processed raw events are kept (the setting, before any storage tier lowers it). */
final class RawRetentionCheck extends AbstractCheck
{
    public function id(): string
    {
        return 'retention.raw';
    }

    public function run(AuditContext $context): Finding
    {
        $days = $context->retentionDays('raw_retention_days');
        $max = $context->profile->rawRetentionMaxDays;
        $over = $days > $max;

        return $this->finding(
            $over ? Finding::ATTENTION : Finding::OK,
            __('Raw events retention'),
            __(':days days', ['days' => $days]),
            $over
                ? __('Processed raw events are kept longer than this profile expects. They are the most detailed data the instance holds.')
                : __('Processed raw events are deleted after this delay by the scheduled maintenance.'),
            __('at most :days days', ['days' => $max]),
            route('health', [], false).'#data-retention',
        );
    }
}
