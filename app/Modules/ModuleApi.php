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
}
