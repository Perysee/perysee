<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AccessLogEntry;
use App\Support\AccessLog;
use App\Support\InstanceSecurity;
use App\Support\TwoFactor;
use App\Support\Ui;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Response;

/** Administrators: the "two-factor required" switch and the access log of the whole instance. */
class InstanceSecurityController extends Controller
{
    public function show(Request $request, InstanceSecurity $security, TwoFactor $twoFactor): Response
    {
        abort_unless($request->user()->is_admin, 403);
        $log = AccessLogEntry::with('user:id,name,email')->orderByDesc('id')->paginate(50, ['*'], 'log')
            ->through(fn (AccessLogEntry $entry): array => AccountSecurityController::entry($entry, true));
        $log->withPath($request->getPathInfo());

        return Ui::render('settings.security', [
            'title' => 'Security and access log',
            'requireTwoFactor' => $security->twoFactorRequired(),
            'adminHasTwoFactor' => $twoFactor->enabled($request->user()),
            'instanceEntries' => $log,
            'retentionDays' => AccessLog::RETENTION_DAYS,
            'securityUpdateUrl' => route('settings.security.update', [], false),
            'accountSecurityUrl' => route('account.security', [], false),
        ]);
    }

    public function update(Request $request, InstanceSecurity $security, TwoFactor $twoFactor): RedirectResponse
    {
        abort_unless($request->user()->is_admin, 403);
        $data = $request->validate(['require_two_factor' => ['required', 'boolean']]);
        // Switching it on without your own second factor would lock you out at the next request.
        if ($data['require_two_factor'] && ! $twoFactor->enabled($request->user())) {
            throw ValidationException::withMessages(['require_two_factor' => __('Turn on two-factor authentication for your own account first.')]);
        }
        $security->requireTwoFactor((bool) $data['require_two_factor']);

        return redirect()->route('settings.security')->with('status', $data['require_two_factor'] ? __('Two-factor authentication is now required for everyone.') : __('Two-factor authentication is no longer required.'));
    }
}
