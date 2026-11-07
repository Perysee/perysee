<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserSession;
use App\Rules\BcryptPassword;
use App\Support\AccessLog;
use App\Support\SignInRecorder;
use App\Support\TwoFactor;
use App\Support\Ui;
use App\Support\UserSessions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Response;

class AuthController extends Controller
{
    /** How long a correct password keeps the second-factor challenge open. */
    public const CHALLENGE_MINUTES = 5;

    public function __construct(private readonly TwoFactor $twoFactor, private readonly UserSessions $sessions, private readonly SignInRecorder $signIns) {}

    public function create(): Response
    {
        return Ui::render('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate(['email' => ['required', 'email', 'max:255'], 'password' => ['required', 'string']]);
        $credentials['email'] = Str::lower($credentials['email']);
        $key = 'login:'.hash('sha256', $credentials['email'].'|'.$request->ip());
        abort_if(RateLimiter::tooManyAttempts($key, 5), 429, 'Too many sign-in attempts. Try again in a minute.');
        // An account with a second factor is never signed in here: the password only opens the challenge.
        $candidate = Auth::guard()->getProvider()->retrieveByCredentials(['email' => $credentials['email']]);
        if ($candidate && $this->twoFactor->enabled($candidate)) {
            if (! Auth::validate($credentials)) {
                return $this->failed($key, $credentials['email'], $candidate, $request);
            }
            RateLimiter::clear($key);

            return $this->openChallenge($candidate, $request);
        }
        if (! Auth::attempt($credentials)) {
            return $this->failed($key, $credentials['email'], $candidate, $request);
        }
        RateLimiter::clear($key);
        $request->session()->regenerate();
        $this->signIns->record($request->user(), $request);

        return redirect()->intended(route('sites.index'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        UserSession::where('session_hash', UserSessions::hash($request->session()->getId()))->delete();
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    public function editPassword(): Response
    {
        return Ui::render('auth.password');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(12), new BcryptPassword],
        ]);
        $request->user()->forceFill(['password' => $data['password'], 'remember_token' => Str::random(60)])->save();
        $request->session()->regenerate();
        // The password hash already ends the other sessions; the list has to say so too.
        $this->sessions->revokeOthers($request->user(), $request->session()->getId());
        $this->sessions->register($request->user(), $request);

        return redirect()->route('password.edit')->with('status', __('Your password has been updated.'));
    }

    private function failed(string $key, string $email, ?User $candidate, Request $request): never
    {
        RateLimiter::hit($key, 60);
        AccessLog::record(AccessLog::LOGIN_FAILED, $candidate, $request, ['stage' => 'password'], $email);
        throw ValidationException::withMessages(['email' => __('These credentials do not match our records.')]);
    }

    /** Keeps who passed the password in the session, but not signed in, until the second factor arrives. */
    private function openChallenge(User $user, Request $request): RedirectResponse
    {
        abort_if(RateLimiter::tooManyAttempts(TwoFactorChallengeController::userKey($user), TwoFactorChallengeController::USER_ATTEMPTS), 429, 'Too many failed codes for this account. Try again later.');
        $request->session()->regenerate();
        $request->session()->put(TwoFactorChallengeController::SESSION_KEY, [
            'user_id' => $user->getKey(),
            'expires_at' => now('UTC')->addMinutes(self::CHALLENGE_MINUTES)->timestamp,
            'fingerprint' => hash('sha256', (string) $user->getAuthPassword()),
        ]);

        return redirect()->route('login.two-factor');
    }
}
