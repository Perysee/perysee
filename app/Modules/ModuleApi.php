<?php

declare(strict_types=1);

namespace App\Modules;

/**
 * The version of the contract between the Core and its modules: the classes a module may extend or call
 * (ModuleProvider, ModuleSettings, ModuleScope::isActiveFor) and the browser object `Perysee`. It changes only when
 * one of them changes in a way that can break an existing module, so a module states the version it was written
 * for in `module.json` ("api") and the Core refuses a module written for another one instead of failing later.
 */
final class ModuleApi
{
    public const VERSION = 1;

    /**
     * The first Perysee release with domain events and the notification and report registries. These are additions
     * inside API 1, so the version does not change: a module that uses them states this release (or a later one) in
     * the "core" field of `module.json`, and an older Core refuses to enable it instead of failing while it runs.
     */
    public const DOMAIN_EVENTS_SINCE = '0.4.0';
}
