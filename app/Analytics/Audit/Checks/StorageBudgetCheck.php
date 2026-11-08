<?php

declare(strict_types=1);

namespace App\Analytics\Audit\Checks;

use App\Analytics\Audit\AuditContext;
use App\Analytics\Audit\Finding;

/** The storage budget, the share of it used at the last hourly measurement and the tier of adaptive retention in force (ADR 011). */
final class StorageBudgetCheck extends AbstractCheck
{
    public function id(): string
    {
        return 'instance.storage';
    }

    public function run(AuditContext $context): Finding
    {
        $budget = $context->storageBudget();
        $ratio = $budget['state']['ratio_percent'] ?? null;
        $quota = array_column($context->attention(), 'message', 'code')['storage'] ?? null;
        $over = $budget['tier'] > 0 || ($ratio !== null && $ratio >= 100) || $quota !== null;

        return $this->finding(
            $over ? Finding::ATTENTION : Finding::OK,
            __('Storage budget'),
            $budget['budget_bytes'] === null
                ? __('no storage budget set, tier 0')
                : __(':ratio of the budget used at the last measurement, mode :mode, tier :tier', ['ratio' => $ratio === null ? __('unknown share') : $ratio.' %', 'mode' => $budget['mode'], 'tier' => $budget['tier']]),
            match (true) {
                $budget['tier'] > 0 => __('A tier of the adaptive retention is in force: the raw events or the hourly aggregates are kept for less time than their settings say, until the database falls back under 80 % of the budget.'),
                $over => (string) ($quota ?? __('The database reached its budget.')),
                default => __('Retention follows the settings. A budget, when set, lowers retention step by step only when the database reaches it.'),
            },
            __('under 100 % of the budget, tier 0'),
            route('health', [], false),
        );
    }
}
