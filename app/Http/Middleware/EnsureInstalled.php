<?php

namespace App\Http\Middleware;

use App\Installation\InstallationState;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureInstalled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! app(InstallationState::class)->isLocked()) {
            return redirect()->route('install');
        }

        return $next($request);
    }
}
