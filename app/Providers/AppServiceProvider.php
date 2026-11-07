<?php

namespace App\Providers;

use App\Modules\Catalog\CatalogSignature;
use App\Modules\Catalog\ModuleCatalog;
use App\Modules\Events\DomainEvents;
use App\Modules\ModuleExtensions;
use App\Modules\ModuleFailures;
use App\Modules\ModuleLoader;
use App\Modules\ModuleRegistry;
use App\Modules\ModuleScope;
use App\Modules\ModuleSettings;
use App\Modules\Notifications\NotificationChannels;
use App\Modules\Reports\ReportSources;
use App\Support\CacheCoordination;
use App\Support\Coordination;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ModuleRegistry::class);
        $this->app->singleton(ModuleLoader::class);
        $this->app->singleton(ModuleFailures::class);
        $this->app->singleton(ModuleCatalog::class);
        $this->app->singleton(CatalogSignature::class, fn (): CatalogSignature => CatalogSignature::fromFile((string) config('perysee.module_catalog.keys_path')));
        $this->app->singleton(ModuleExtensions::class);
        $this->app->singleton(ModuleSettings::class);
        $this->app->singleton(ModuleScope::class);
        // Module events and extension registries (ADR 005).
        $this->app->singleton(DomainEvents::class);
        $this->app->singleton(NotificationChannels::class);
        $this->app->singleton(ReportSources::class);
        // A module may rebind this before its first use; the default keeps the state in the application cache.
        $this->app->singleton(Coordination::class, CacheCoordination::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        app()->setLocale('en');
        if (! app()->environment('local', 'testing')) {
            config(['session.secure' => true]);
        }
        if ($this->app->runningInConsole() || ! request()->is('up')) {
            // Once the application has booted, a module provider is registered and booted inside the loader's own try/catch.
            $this->app->booted(fn () => $this->app->make(ModuleLoader::class)->load());
        }
    }
}
