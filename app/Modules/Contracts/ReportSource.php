<?php

declare(strict_types=1);

namespace App\Modules\Contracts;

use App\Modules\ModuleApi;
use App\Modules\ModuleProvider;
use App\Modules\Reports\ReportSection;
use Illuminate\Support\Carbon;

/**
 * Figures a module contributes to a periodic report (a digest, for instance), declared with
 * {@see ModuleProvider::reportSource()}.
 *
 * @api Part of module API 1, available from the Perysee release named by {@see ModuleApi::DOMAIN_EVENTS_SINCE}.
 */
interface ReportSource
{
    /** A short name for administrators, in plain text. */
    public function label(): string;

    /**
     * The sections for one site over a period. $from and $to are UTC instants; the period is [$from, $to).
     *
     * @return list<ReportSection>
     */
    public function sections(int $siteId, Carbon $from, Carbon $to): array;
}
