<?php

namespace App\Http\Middleware;

use App\Installation\InstallationState;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class BootstrapInstallation
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('up', 'api/*', 'lueur.js')) {
            return $next($request);
        }
        abort_if(! config('app.key') && app(InstallationState::class)->isLocked(), 503, 'Restore the original application key before continuing.');
        if (! config('app.key') || ($request->is('install*') && ! app(InstallationState::class)->isLocked())) {
            abort_if(app()->configurationIsCached(), 503, 'Remove bootstrap/cache/config.php before first installation.');
            try {
                app(InstallationState::class)->bootstrap();
            } catch (ValidationException $exception) {
                throw $exception;
            } catch (\Throwable) {
                return response('Unable to initialize installation. Check write permissions on the application directory and storage.', 503);
            }
        }
        $response = $next($request);
        if (! $request->is('modules/*/assets/*')) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }
        if (! $response->headers->has('Referrer-Policy')) {
            // An invitation address carries a secret: it must not leak through the Referer of any link or resource.
            $response->headers->set('Referrer-Policy', $request->is('invitations/*') ? 'no-referrer' : 'same-origin');
        }
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');

        return $response;
    }
}
