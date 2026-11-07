<?php

namespace App\Http\Middleware;

use App\Models\ShareLink;
use App\Support\Coordination;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Finds the share link behind the token of the URL, and the site it covers. The link and the site are put on the
 * request by hand: they never come from route-model binding, and nothing else (no account, no other site) is
 * reachable from a share route. Every reason a link cannot be used gives the same answer.
 */
class ResolveShareLink
{
    public function __construct(private Coordination $coordination) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->route('token');
        $link = strlen($token) <= 64 ? ShareLink::with('website')->where('token_hash', ShareLink::hashToken($token))->first() : null;
        $site = $link?->website;
        $usable = $link !== null
            && hash_equals($link->token_hash, ShareLink::hashToken($token))
            && $link->status() === 'active'
            && $site !== null && $site->deleting_at === null && ! $site->is_demo;

        if (! $usable) {
            // Only misses count, so a visitor with a good link is never limited by this.
            if (! $this->coordination->throttle('share:miss:'.sha1((string) $request->ip()), 60, 60)) {
                return response('Too many requests.', 429);
            }

            return self::unavailable($request);
        }

        $request->attributes->set('share_link', $link);
        $request->attributes->set('share_site', $site);
        $request->attributes->set('share_unlocked', $link->password_hash === null || $request->session()->get('share_unlocked.'.$link->id) === $link->secret_version);

        return $next($request);
    }

    /** The one answer for an unknown, revoked, expired, locked-out or deleted link. */
    public static function unavailable(Request $request): Response|JsonResponse
    {
        if ($request->expectsJson() || $request->is('s/*/api/*')) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        return Inertia::render('Share/Unavailable')->toResponse($request)->setStatusCode(404);
    }
}
