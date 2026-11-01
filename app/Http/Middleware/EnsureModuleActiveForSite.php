<?php

namespace App\Http\Middleware;

use App\Models\Website;
use App\Modules\ModuleScope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applied to every route a module registers: when the route concerns a site, the module must be active for it.
 */
class EnsureModuleActiveForSite
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        $site = $request->route('website');
        if ($site !== null && ! $site instanceof Website) {
            $site = Website::find($site);
        }
        abort_unless($site === null || app(ModuleScope::class)->isActiveFor($module, $site), 404);

        return $next($request);
    }
}
