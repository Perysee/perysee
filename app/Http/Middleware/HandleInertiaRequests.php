<?php

namespace App\Http\Middleware;

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

        return [...parent::share($request),
            'currentUrl' => $request->url(),
            'assets' => ['logo' => asset('logo.svg'), 'world' => asset('maps/world.json'), 'brands' => asset('brands')],
            'auth' => $user ? [...$user->only(['id', 'name', 'email']), 'is_admin' => (bool) $user->is_admin] : null,
            'navigation' => fn () => $user ? $user->websites()->whereNull('deleting_at')->orderBy('name')->limit(50)->get()->map(fn ($site) => ['id' => $site->id, 'name' => $site->name, 'domain' => $site->domain, 'url' => route('sites.show', $site), 'favicon_url' => route('sites.favicon', $site)])->all() : [],
            'links' => $links,
            'docsUrl' => rtrim(config('perysee.docs_url'), '/'),
            'flash' => ['status' => fn () => $request->session()->get('status')],
        ];
    }
}
