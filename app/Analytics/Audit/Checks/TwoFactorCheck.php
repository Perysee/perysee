<?php

declare(strict_types=1);

namespace App\Analytics\Audit\Checks;

use App\Analytics\Audit\AuditContext;
use App\Analytics\Audit\Finding;

/** Administrators without a confirmed second factor, and the instance switch that requires one from everybody (ADR 007). */
final class TwoFactorCheck extends AbstractCheck
{
    public function id(): string
    {
        return 'access.two_factor';
    }

    public function run(AuditContext $context): Finding
    {
        ['total' => $total, 'without_two_factor' => $without] = $context->administrators();
        $required = $context->twoFactorRequired();

        return $this->finding(
            $required || $without === 0 ? Finding::OK : Finding::ATTENTION,
            __('Two-factor authentication of administrators'),
            __(':without of :total administrators without two-factor authentication, required for every account: :required', ['without' => $without, 'total' => $total, 'required' => $required ? __('yes') : __('no')]),
            match (true) {
                $required && $without > 0 => __('Two-factor authentication is required: an administrator without it is sent to set it up at the next page.'),
                $required => __('Two-factor authentication is required for every account and every administrator has it.'),
                $without === 0 => __('Every administrator has two-factor authentication, though the instance does not require it of other accounts.'),
                default => __('Some administrators sign in with a password only. The requirement can be switched on in the security settings once your own account has two-factor authentication.'),
            },
            __('every administrator protected, or the requirement switched on'),
            route('settings.security', [], false),
        );
    }
}
