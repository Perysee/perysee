<?php

namespace App\Http\Controllers;

use App\Installation\Diagnostics;
use App\Installation\InstallationState;
use App\Installation\Installer;
use App\Rules\BcryptPassword;
use App\Support\Ui;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Response;

class InstallationController extends Controller
{
    public function __construct(private Installer $installer, private InstallationState $state, private Diagnostics $diagnostics) {}

    public function show(Request $request): Response
    {
        $this->installer->assertAvailable();

        return Ui::render('install', [
            'checks' => $this->diagnostics->checks($request),
            'authorized' => $this->authorized($request),
            'migrated' => $this->migrated($request),
        ]);
    }

    public function unlock(Request $request): RedirectResponse
    {
        $this->installer->assertAvailable();
        $data = $request->validate(['installation_secret' => ['required', 'string', 'max:128']]);
        $key = 'install:'.$request->ip();
        abort_if(RateLimiter::tooManyAttempts($key, 5), 429, 'Too many installation attempts. Try again in a minute.');
        if (! $this->state->acceptsSecret($data['installation_secret'])) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['installation_secret' => 'The installation secret is incorrect.']);
        }
        RateLimiter::clear($key);
        $request->session()->regenerate();
        $request->session()->put('installation.authorization', hash('sha256', $data['installation_secret']));

        return redirect()->route('install');
    }

    public function database(Request $request): RedirectResponse
    {
        $this->installer->assertAvailable();
        abort_unless($this->authorized($request), 403);
        abort_unless($this->diagnostics->passes($request), 422, 'Resolve the installation checks before continuing.');
        $data = $request->validate([
            'db_host' => ['required', 'string', 'max:253', 'regex:/^[a-zA-Z0-9.:-]+$/'],
            'db_port' => ['required', 'integer', 'between:1,65535'],
            'db_database' => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'db_username' => ['required', 'string', 'max:80', 'not_regex:/[\r\n\x00]/'],
            'db_password' => ['nullable', 'string', 'max:255', 'not_regex:/[\r\n\x00]/'],
        ]);
        $this->state->exclusive(function () use ($data, $request): void {
            abort_unless($this->authorized($request), 403);
            $request->session()->forget('installation.migrated');
            $this->installer->prepareDatabase($data, $request->getSchemeAndHttpHost());
            $request->session()->put('installation.migrated', $this->installer->configurationFingerprint());
        });

        return redirect()->route('install');
    }

    public function administrator(Request $request): RedirectResponse
    {
        $this->installer->assertAvailable();
        abort_unless($this->authorized($request) && $this->migrated($request), 403);
        abort_unless($this->diagnostics->passes($request), 422, 'Resolve the installation checks before continuing.');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(12), new BcryptPassword],
        ]);
        $this->state->exclusive(function () use ($data, $request): void {
            abort_unless($this->authorized($request) && $this->migrated($request), 403);
            $this->installer->createAdministrator($data);
        });
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'Perysee is installed. Sign in to add your first site.');
    }

    private function authorized(Request $request): bool
    {
        return ! $this->state->isLocked() && is_file($this->state->secretPath())
            && hash_equals(hash('sha256', trim(file_get_contents($this->state->secretPath()))), (string) $request->session()->get('installation.authorization'));
    }

    private function migrated(Request $request): bool
    {
        return $request->session()->get('installation.migrated') === $this->installer->configurationFingerprint();
    }
}
