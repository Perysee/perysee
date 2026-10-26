<?php

namespace App\Http\Controllers;

use App\Rules\BcryptPassword;
use App\Support\Ui;
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
        if (! Auth::attempt($credentials)) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['email' => __('These credentials do not match our records.')]);
        }
        RateLimiter::clear($key);
        $request->session()->regenerate();

        return redirect()->intended(route('sites.index'));
    }

    public function destroy(Request $request): RedirectResponse
    {
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

        return redirect()->route('password.edit')->with('status', __('Your password has been updated.'));
    }
}
