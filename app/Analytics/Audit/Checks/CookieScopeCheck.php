<?php

declare(strict_types=1);

namespace App\Analytics\Audit\Checks;

use App\Analytics\Audit\AuditContext;
use App\Analytics\Audit\Finding;

/**
 * A static statement about the tracker, checked by TrackerConstantsTest against the text of lueur.js: the cookie is
 * named after the site and has no Domain attribute, so the browser sends it to the exact host that set it and no
 * identifier is shared between sites, domains or subdomains. Only the profiles that ask for it list this check.
 */
final class CookieScopeCheck extends AbstractCheck
{
    public function id(): string
    {
        return 'measurement.cookie_scope';
    }

    public function run(AuditContext $context): Finding
    {
        return $this->finding(
            Finding::OK,
            __('Cookie specific to the site'),
            __('one cookie per site, with no Domain attribute'),
            __('The name of the cookie carries the identifier of the site and it is set for the host of the page only, so no identifier is shared between sites, domains or subdomains.'),
            __('no identifier shared between domains'),
        );
    }
}
