<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\AccessLog;
use App\Support\SignInRecorder;
use App\Support\TwoFactor;
use App\Support\Ui;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The second step of a sign-in. The visitor is a guest until the code is accepted: the only thing the session
 * holds meanwhile is who passed the password, for a few minutes. The session id changes once the code is accepted.
 */
class TwoFactorChallengeController extends Controller
{
    public const SESSION_KEY = 'login.2fa';

    /** Failed codes per account and hour, from any address. */
    public const USER_ATTEMPTS = 10;

    /** Failed codes per account and address, and per minute. */
    private const ADDRESS_ATTEMPTS = 5;

    public function __construct(private readonly TwoFactor $twoFactor, private readonly SignInRecorder $signIns) {}

    public static function userKey(User $user): string
    {
        return '2fa-user:'.$user->getKey();
    }

    public function show(Request $request): Response|RedirectResponse
    {
        if (! $this->pending($request)) {
            return redirect()->route('login');
        }

        return Ui::render('auth.two-factor', ['title' => 'Two-factor authentication', 'challengeUrl' => route('login.two-factor.store', [], false)])
            ->toResponse($request)->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:40']]);
        $user = $this->pending($request);
        if (! $user) {
            return redirect()->route('login')->withErrors(['email' => __('Your sign-in expired. Enter your password again.')]);
        }
        $address = '2fa:'.hash('sha256', $user->getKey().'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($address, self::ADDRESS_ATTEMPTS) || RateLimiter::tooManyAttempts(self::userKey($user), self::USER_ATTEMPTS)) {
            // Too many wrong codes: the challenge is closed and the password has to be entered again.
            $request->session()->forget(self::SESSION_KEY);

            return redirect()->route('login')->withErrors(['email' => __('Too many incorrect codes. Try again later.')]);
        }
        // The attempt is counted before the check, so parallel requests cannot all pass the limiter with the same
        // count; a success gives it back.
        RateLimiter::hit($address, 60);
        RateLimiter::hit(self::userKey($user), 3600);
        $method = $this->twoFactor->verifyChallenge($user, $data['code']);
        if ($method === null) {
            AccessLog::record(AccessLog::LOGIN_FAILED, $user, $request, ['stage' => 'two_factor']);
            throw ValidationException::withMessages(['code' => __('The code is not valid.')]);
        }
        RateLimiter::clear($address);
        RateLimiter::clear(self::userKey($user));
        $request->session()->forget(self::SESSION_KEY);
        Auth::login($user);
        $request->session()->regenerate();
        $this->signIns->record($user, $request, method: $method);

        return redirect()->intended(route('sites.index'));
    }

    /** The account that passed its password less than five minutes ago and has not changed it since. */
    private function pending(Request $request): ?User
    {
        $state = $request->session()->get(self::SESSION_KEY);
        if (! is_array($state) || ($state['expires_at'] ?? 0) < now('UTC')->timestamp) {
            $request->session()->forget(self::SESSION_KEY);

            return null;
        }
        $user = User::find($state['user_id'] ?? 0);
        if (! $user || ! hash_equals((string) ($state['fingerprint'] ?? ''), hash('sha256', (string) $user->getAuthPassword())) || ! $this->twoFactor->enabled($user)) {
            $request->session()->forget(self::SESSION_KEY);

            return null;
        }

        return $user;
    }
}
