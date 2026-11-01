<?php

namespace App\Http\Middleware;

use App\Models\Website;
use App\Modules\ModuleExtensions;
use App\Modules\ModuleLoader;
use App\Modules\ModuleRegistry;
use App\Modules\ModuleScope;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'inertia';

    public function share(Request $request): array
    {
        $user = $request->user();
        $links = [];
        foreach (['sites.index', 'sites.create', 'sites.store', 'dashboard', 'login', 'login.store', 'logout', 'password.edit', 'password.update', 'health', 'health.update', 'health.geolocation', 'settings', 'install', 'install.unlock', 'install.database', 'install.administrator'] as $name) {
            $links[$name] = route($name);
        }
        $links['modules'] = route('modules.index');

        return [...parent::share($request),
            'currentUrl' => $request->url(),
            'assets' => ['logo' => asset('logo.svg'), 'world' => asset('maps/world.json'), 'brands' => asset('brands')],
            'auth' => $user ? [...$user->only(['id', 'name', 'email']), 'is_admin' => (bool) $user->is_admin] : null,
            'navigation' => fn () => $user ? $user->websites()->whereNull('deleting_at')->orderBy('name')->limit(50)->get()->map(fn ($site) => ['id' => $site->id, 'name' => $site->name, 'domain' => $site->domain, 'url' => route('sites.show', $site), 'favicon_url' => route('sites.favicon', $site)])->all() : [],
            'links' => $links,
            'branding' => fn () => app(ModuleExtensions::class)->branding($this->currentSite($request)),
            'modules' => fn () => $user ? [
                'navigation' => app(ModuleExtensions::class)->navigation((bool) $user->is_admin, $this->currentSite($request)),
                'data' => app(ModuleExtensions::class)->data($request, $this->currentSite($request)),
                'active' => $this->activeModules($this->currentSite($request)),
                'scripts' => $this->moduleAssets('module.js'),
                'styles' => $this->moduleAssets('module.css'),
            ] : ['navigation' => ['workspace' => [], 'admin' => [], 'site' => []], 'data' => [], 'active' => [], 'scripts' => [], 'styles' => []],
            'docsUrl' => rtrim(config('perysee.docs_url'), '/'),
            'flash' => ['status' => fn () => $request->session()->get('status'), 'error' => fn () => $request->session()->get('error')],
        ];
    }

    private function currentSite(Request $request): ?Website
    {
        $site = $request->route('website');

        return $site instanceof Website ? $site : null;
    }

    /**
     * Enabled modules that loaded and are active for the site being viewed, or for the instance when there is none.
     *
     * @return list<string>
     */
    private function activeModules(?Website $site): array
    {
        $failures = app(ModuleLoader::class)->failures();
        $scope = app(ModuleScope::class);

        return array_values(array_filter(
            array_keys(app(ModuleRegistry::class)->enabled()),
            fn (string $id): bool => ! isset($failures[$id]) && $scope->isActiveFor($id, $site),
        ));
    }

    /**
     * URLs of a compiled file for every enabled module that loaded successfully and ships it.
     *
     * @return list<string>
     */
    private function moduleAssets(string $file): array
    {
        $failures = app(ModuleLoader::class)->failures();
        $urls = [];
        foreach (app(ModuleRegistry::class)->enabled() as $id => $module) {
            if (! isset($failures[$id]) && is_file($module->path.'/dist/'.$file)) {
                $urls[] = route('modules.assets', [$id, $file]).'?v='.$module->manifest->version;
            }
        }

        return $urls;
    }
}
