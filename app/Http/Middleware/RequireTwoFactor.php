<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\InstanceSecurity;
use App\Support\TwoFactor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * When the administrator requires two-factor authentication, an account without it can only reach the page that
 * sets it up (and sign out). There is no grace period.
 */
class RequireTwoFactor
{
    private const ALLOWED = ['logout', 'account.security', 'account.security.*'];

    public function __construct(private readonly InstanceSecurity $security, private readonly TwoFactor $twoFactor) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || ! $this->security->twoFactorRequired() || $request->routeIs(...self::ALLOWED) || $this->twoFactor->enabled($user)) {
            return $next($request);
        }
        $message = __('Your administrator requires two-factor authentication. Set it up to continue.');
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 403);
        }

        return redirect()->route('account.security')->with('error', $message);
    }
}
