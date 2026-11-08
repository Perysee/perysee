<?php

declare(strict_types=1);

namespace App\Analytics\Audit\Checks;

use App\Analytics\Audit\AuditContext;
use App\Analytics\Audit\Finding;
use App\Analytics\InstanceHealth;

/** The cron job and its heartbeat, as {@see InstanceHealth::attention()} reports them. */
final class SchedulerCheck extends AbstractCheck
{
    public function id(): string
    {
        return 'instance.scheduler';
    }

    public function run(AuditContext $context): Finding
    {
        $codes = array_column($context->attention(), 'message', 'code');
        $scheduler = $codes['scheduler'] ?? null;
        $backlog = $codes['backlog'] ?? null;

        return $this->finding(
            $scheduler !== null ? Finding::PROBLEM : ($backlog !== null ? Finding::ATTENTION : Finding::OK),
            __('Scheduler and heartbeat'),
            $scheduler ?? $backlog ?? __('the scheduler reports in and no event waits for consolidation'),
            $scheduler !== null || $backlog !== null
                ? __('Reports are built by the scheduled task. The cron line to add is shown on the Instance health page.')
                : __('The heartbeat is recent and the oldest unprocessed event is less than fifteen minutes old.'),
            __('heartbeat less than 10 minutes old'),
            route('health', [], false),
        );
    }
}
