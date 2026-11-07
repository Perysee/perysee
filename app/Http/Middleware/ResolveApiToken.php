<?php

namespace App\Http\Middleware;

use App\Http\Api\ApiError;
use App\Installation\InstallationState;
use App\Models\ApiToken;
use App\Support\Coordination;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Finds the API token behind the bearer secret, and the site it covers. The token and the site are put on the
 * request by hand: they never come from route-model binding, and no account or other site is reachable from an
 * API route. Every reason a token cannot be used gives the same answer.
 */
class ResolveApiToken
{
    /** The secret is only ever looked up by its hash, and a longer value cannot be one of ours. */
    private const MAX_LENGTH = 64;

    /** `last_used_at` is stamped at most once per window, in one statement, so a busy token costs almost no writes. */
    private const USE_WINDOW_MINUTES = 10;

    public function __construct(private Coordination $coordination) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! app(InstallationState::class)->isLocked()) {
            throw new ApiError(503, 'unavailable', 'The service is temporarily unavailable.');
        }
        // Only the Authorization header carries a secret; a token in the query string or the body is never read.
        $secret = (string) $request->bearerToken();
        $token = $secret !== '' && strlen($secret) <= self::MAX_LENGTH ? ApiToken::with('website')->where('token_hash', ApiToken::hashToken($secret))->first() : null;
        $site = $token?->website;
        $usable = $token !== null
            && hash_equals($token->token_hash, ApiToken::hashToken($secret))
            && $token->status() === 'active'
            && $site !== null && $site->deleting_at === null && ! $site->is_demo;

        if (! $usable) {
            // Only misses count, so a client with a good token is never limited by this.
            if (! $this->coordination->throttle('api:miss:'.sha1((string) $request->ip()), 60, 60)) {
                throw new ApiError(429, 'rate_limited', 'Too many requests.', [], ['Retry-After' => '60']);
            }
            throw ApiError::invalidToken();
        }

        $limit = max(1, $token->rate_per_minute);
        $key = 'api:token:'.$token->id;
        if (RateLimiter::tooManyAttempts($key, $limit)) {
            $wait = max(1, RateLimiter::availableIn($key));
            throw new ApiError(429, 'rate_limited', 'Too many requests for this token.', [], ['Retry-After' => (string) $wait] + $this->rateHeaders($limit, 0, $wait));
        }
        RateLimiter::hit($key, 60);
        $wait = max(1, RateLimiter::availableIn($key));

        $this->recordUse($token);
        $request->attributes->set('api_token', $token);
        $request->attributes->set('api_site', $site);

        $response = $next($request);
        foreach ($this->rateHeaders($limit, RateLimiter::remaining($key, $limit), $wait) as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }

    /** @return array<string, string> */
    private function rateHeaders(int $limit, int $remaining, int $resetIn): array
    {
        return ['X-RateLimit-Limit' => (string) $limit, 'X-RateLimit-Remaining' => (string) max(0, $remaining), 'X-RateLimit-Reset' => (string) (now()->timestamp + $resetIn)];
    }

    private function recordUse(ApiToken $token): void
    {
        if ($token->last_used_at !== null && $token->last_used_at->gt(now()->subMinutes(self::USE_WINDOW_MINUTES))) {
            return;
        }
        ApiToken::whereKey($token->id)
            ->where(fn ($query) => $query->whereNull('last_used_at')->orWhere('last_used_at', '<=', now()->subMinutes(self::USE_WINDOW_MINUTES)))
            ->update(['last_used_at' => now(), 'use_count' => DB::raw('use_count + 1')]);
    }
}
