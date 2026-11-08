<?php

declare(strict_types=1);

namespace App\Analytics\Audit\Checks;

use App\Analytics\Audit\AuditContext;
use App\Analytics\Audit\Finding;

/** How many accounts can change every setting of the instance. */
final class AdministratorCountCheck extends AbstractCheck
{
    /** More administrators than this are reported: each one can read and change everything. */
    public const MAX = 5;

    public function id(): string
    {
        return 'access.administrators';
    }

    public function run(AuditContext $context): Finding
    {
        $total = $context->administrators()['total'];

        return $this->finding(
            $total > self::MAX ? Finding::ATTENTION : Finding::OK,
            __('Number of administrators'),
            trans_choice('{0} no administrator|{1} :count administrator|[2,*] :count administrators', $total),
            __('An administrator reads every site, manages the accounts and changes the instance settings. Members of a site have narrower rights.'),
            __('at most :count administrators', ['count' => self::MAX]),
        );
    }
}
