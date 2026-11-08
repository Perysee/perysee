<?php

declare(strict_types=1);

namespace App\Modules;

use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureModuleActiveForSite;
use App\Http\Middleware\RequireTwoFactor;
use App\Modules\Contracts\DomainEvent;
use App\Modules\Contracts\NotificationChannel;
use App\Modules\Contracts\ReportSource;
use App\Modules\Events\DomainEvents;
use App\Modules\Notifications\NotificationChannels;
use App\Modules\Reports\ReportSources;
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

    /**
     * Subscribes to a domain event (see the catalog in `App\Modules\Events`). The listener receives the event, runs only
     * while this module is enabled and, for an event about a site, only on the sites the module is active on. An exception
     * in it is counted against this module and never reaches the Core or another module. Delivery is best effort
     * (at most once, no ordering): do not rely on it for data that must not be lost.
     *
     * Available from the Perysee release in {@see ModuleApi::DOMAIN_EVENTS_SINCE}: `module.json` must require it in "core".
     * An event added later (a `SINCE` constant on its class) needs the release it names, for instance 0.5.0 for
     * `StorageBudgetExceeded`.
     *
     * @param  class-string<DomainEvent>  $event
     * @param  Closure(DomainEvent): mixed|class-string  $listener  A closure, or the name of a class with an __invoke method.
     */
    protected function listen(string $event, Closure|string $listener): void
    {
        $this->requireDomainEvents('listen');
        // An event added after the first release of the bus names its own release: an older Core would not have the class at all.
        $since = defined($event.'::SINCE') ? (string) constant($event.'::SINCE') : ModuleApi::DOMAIN_EVENTS_SINCE;
        if (version_compare($this->module->manifest->core, $since, '<')) {
            throw new InvalidManifest("Module {$this->module->manifest->id} listens to {$event}, which needs Perysee {$since} or later: set \"core\" to at least that version in module.json.");
        }
        $this->app->make(DomainEvents::class)->listen($this->module->manifest->id, $event, $listener);
    }

    /**
     * Declares a notification channel. $key is "{module id}.{name}". Other modules and the Core send through
     * `NotificationChannels::send($key, $target, $notification)`.
     *
     * @param  NotificationChannel|class-string<NotificationChannel>  $channel
     */
    protected function notificationChannel(string $key, NotificationChannel|string $channel): void
    {
        $this->requireDomainEvents('notificationChannel');
        $this->app->make(NotificationChannels::class)->register($this->module->manifest->id, $key, $channel);
    }

    /**
     * Declares a source of figures for periodic reports. $key is "{module id}.{name}".
     *
     * @param  ReportSource|class-string<ReportSource>  $source
     */
    protected function reportSource(string $key, ReportSource|string $source): void
    {
        $this->requireDomainEvents('reportSource');
        $this->app->make(ReportSources::class)->register($this->module->manifest->id, $key, $source);
    }

    /**
     * A module that uses these methods must say so in its manifest, so that an older Core refuses to enable it.
     */
    private function requireDomainEvents(string $method): void
    {
        if (version_compare($this->module->manifest->core, ModuleApi::DOMAIN_EVENTS_SINCE, '<')) {
            throw new InvalidManifest("Module {$this->module->manifest->id} calls {$method}(), which needs Perysee ".ModuleApi::DOMAIN_EVENTS_SINCE.' or later: set "core" to at least that version in module.json.');
        }
    }
}
