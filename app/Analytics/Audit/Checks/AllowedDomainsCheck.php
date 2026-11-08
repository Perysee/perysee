<?php

declare(strict_types=1);

namespace App\Analytics\Audit\Checks;

use App\Analytics\Audit\AuditContext;
use App\Analytics\Audit\Finding;

/**
 * The hosts each site accepts. The collector compares the host of an event to this list exactly, so an empty list
 * accepts nothing (the site measures nothing) and a wildcard never matches: both are mistakes, not permissions.
 */
final class AllowedDomainsCheck extends AbstractCheck
{
    public function id(): string
    {
        return 'sites.allowed_domains';
    }

    public function run(AuditContext $context): Finding
    {
        $empty = $wildcard = [];
        foreach ($context->sites()['sites'] as $site) {
            if ($site['allowed_domains'] === []) {
                $empty[] = $site['name'];
            } elseif (array_filter($site['allowed_domains'], fn (string $host): bool => str_contains($host, '*')) !== []) {
                $wildcard[] = $site['name'];
            }
        }
        $status = $empty !== [] ? Finding::PROBLEM : ($wildcard !== [] ? Finding::ATTENTION : Finding::OK);

        return $this->finding(
            $status,
            __('Accepted hosts of each site'),
            __(':empty with an empty list, :wildcard with a wildcard', ['empty' => $this->sites(count($empty)), 'wildcard' => $this->sites(count($wildcard))]),
            match (true) {
                $empty !== [] => __('These sites accept no host, so the collector refuses every event they send: :sites.', ['sites' => $this->names($empty)]),
                $wildcard !== [] => __('These sites list a host with a wildcard. The collector compares hosts exactly, so that entry never matches: :sites.', ['sites' => $this->names($wildcard)]),
                default => __('Every site lists the exact hosts it accepts.'),
            },
            __('a non-empty list of exact hosts'),
            route('sites.index', [], false),
        );
    }
}
