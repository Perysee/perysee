<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\UserSessions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the list of open sessions of the signed-in account up to date. Most requests cost nothing: the row is
 * written when the session first appears and then at most every ten minutes.
 */
class TrackSession
{
    public function __construct(private readonly UserSessions $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->hasSession() && $request->session()->isStarted() && ($user = $request->user()) && $this->sessions->due($request)) {
            try {
                $this->sessions->register($user, $request);
            } catch (\Throwable $exception) {
                // A page must still open when the registry cannot be written (for instance mid-upgrade).
                report($exception);
            }
        }

        return $next($request);
    }
}
