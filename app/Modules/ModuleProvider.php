<?php

declare(strict_types=1);

namespace App\Modules;

use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureModuleActiveForSite;
use App\Http\Middleware\RequireTwoFactor;
use Closure;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Base class of a module's ModuleServiceProvider.
 *
 * @api Part of module API 1: protected methods below keep their meaning until the API version changes.
 */
abstract class ModuleProvider extends ServiceProvider
{
    public function __construct($app, protected Module $module)
    {
        parent::__construct($app);
    }

    /**
     * Routes are served under /modules/{id}, for signed-in users (who must have a second factor when the instance requires one), with route names prefixed by "{id}.".
     */
    protected function loadModuleRoutes(string $file): void
    {
        $id = $this->module->manifest->id;
        Route::middleware(['web', EnsureInstalled::class, 'auth', RequireTwoFactor::class, EnsureModuleActiveForSite::class.':'.$id])
            ->prefix('modules/'.$id)
            ->name($id.'.')
            ->group($file);
        Route::getRoutes()->refreshNameLookups();
        Route::getRoutes()->refreshActionLookups();
    }

    /**
     * Routes that need no session, served under /modules/{id}/public, such as an image shown on the sign-in page.
     */
    protected function loadPublicRoutes(string $file): void
    {
        $id = $this->module->manifest->id;
        Route::middleware(['web', EnsureInstalled::class])
            ->prefix('modules/'.$id.'/public')
            ->name($id.'.public.')
            ->group($file);
        Route::getRoutes()->refreshNameLookups();
        Route::getRoutes()->refreshActionLookups();
    }

    /**
     * Adds an entry to an existing navigation zone: "workspace", "admin" or "site". $route is the full route name; a "site" route receives the current site as its parameter.
     */
    protected function addNavigation(string $zone, string $label, string $icon, string $route): void
    {
        $this->app->make(ModuleExtensions::class)->addNavigation($this->module->manifest->id, $zone, $label, $icon, $route);
    }

    /**
     * Registers scheduled tasks. They exist only while the module is enabled.
     *
     * @param  Closure(Schedule): void  $callback
     */
    protected function schedule(Closure $callback): void
    {
        $this->callAfterResolving(Schedule::class, $callback);
    }

    /**
     * Data for this module's screens and slots, available to signed-in users as modules.data.{id}.
     *
     * @param  Closure(Request): array<string, mixed>  $resolver
     */
    protected function shareData(Closure $resolver): void
    {
        $this->app->make(ModuleExtensions::class)->shareData($this->module->manifest->id, $resolver);
    }

    /**
     * Overrides the instance name, logo address and accent colour.
     *
     * @param  Closure(): array<string, mixed>  $resolver
     */
    protected function brand(Closure $resolver): void
    {
        $this->app->make(ModuleExtensions::class)->brand($this->module->manifest->id, $resolver);
    }

    /**
     * Adds a status card to the instance health page, for administrators: a title, a status ("ok" or
     * "attention") and a few label/value lines. Return plain text only.
     *
     * @param  Closure(): array<string, mixed>  $resolver
     */
    protected function healthCard(Closure $resolver): void
    {
        $this->app->make(ModuleExtensions::class)->healthCard($this->module->manifest->id, $resolver);
    }
}
