<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/** Ten requests a minute per address on the invitation links, whatever the token. */
class ThrottleInvitationLinks
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = 'invitation-ip:'.$request->ip();
        abort_if(RateLimiter::tooManyAttempts($key, 10), 429, 'Too many attempts. Try again in a minute.');
        RateLimiter::hit($key, 60);

        return $next($request);
    }
}
