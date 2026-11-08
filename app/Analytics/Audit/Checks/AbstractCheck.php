<?php

declare(strict_types=1);

namespace App\Analytics\Audit\Checks;

use App\Analytics\Audit\Check;
use App\Analytics\Audit\Finding;

abstract class AbstractCheck implements Check
{
    protected function finding(string $status, string $title, string $value, string $explanation, ?string $threshold = null, ?string $settingUrl = null): Finding
    {
        return new Finding($this->id(), $title, $status, $value, $explanation, $threshold, $settingUrl);
    }

    /** "3 sites, 2 sites": a count with its noun, translatable as a whole. */
    protected function sites(int $count): string
    {
        return trans_choice('{1} :count site|[2,*] :count sites', $count);
    }

    /** Names of at most five sites, for a value line. @param  list<string>  $names */
    protected function names(array $names): string
    {
        $shown = array_slice($names, 0, 5);

        return implode(', ', $shown).(count($names) > 5 ? ' …' : '');
    }
}
