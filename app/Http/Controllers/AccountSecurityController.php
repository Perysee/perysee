<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AccessLogEntry;
use App\Models\UserSession;
use App\Support\AccessLog;
use App\Support\InstanceSecurity;
use App\Support\TwoFactor;
use App\Support\Ui;
use App\Support\UserSessions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/** The account's own security: two-factor authentication, open sessions ("connected devices") and recent activity. */
class AccountSecurityController extends Controller
{
    public function __construct(
        private readonly TwoFactor $twoFactor,
        private readonly UserSessions $sessions,
        private readonly InstanceSecurity $instance,
    ) {}

    /** Shared by the account and instance pages: one row of the access log as the interface shows it. */
    public static function entry(AccessLogEntry $entry, bool $withAccount = false): array
    {
        return [
            'id' => $entry->id,
            'event' => $entry->event,
            'account' => $withAccount ? ($entry->user ? ['name' => $entry->user->name, 'email' => $entry->user->email] : null) : null,
            'email_tried' => $entry->email_tried,
            'ip' => $entry->ip,
            'device' => $entry->device,
            'context' => $entry->context,
            'created_at' => $entry->created_at?->toIso8601String(),
        ];
    }

    public function show(Request $request): Response
    {
        $user = $request->user();
        $currentHash = UserSessions::hash($request->session()->getId());
        $pending = $this->twoFactor->pending($user);
        $setup = null;
        if ($pending) {
            $uri = $this->twoFactor->otpauthUri($user, $pending->secret);
            $setup = ['secret' => $this->twoFactor->groupedSecret($pending->secret), 'uri' => $uri, 'qr' => $this->twoFactor->qrSvg($uri)];
        }
        $enabled = ! $pending && $this->twoFactor->enabled($user);
        $log = AccessLogEntry::where('user_id', $user->getKey())->orderByDesc('id')->paginate(10, ['*'], 'log')
            ->through(fn (AccessLogEntry $entry): array => self::entry($entry));
        $log->withPath($request->getPathInfo());

        return Ui::render('account.security', [
            'title' => 'Security',
            'twoFactor' => [
                'enabled' => $enabled,
                'required' => $this->instance->twoFactorRequired(),
                'remaining_codes' => $enabled ? $this->twoFactor->remainingRecoveryCodes($user) : 0,
                'setup' => $setup,
            ],
            'recoveryCodes' => $request->session()->pull('recovery_codes'),
            'openSessions' => $user->userSessions()->orderByDesc('last_seen_at')->limit(50)->get()->map(fn (UserSession $row): array => [
                'id' => $row->id,
                'device' => $row->device,
                'ip' => $row->ip,
                'created_at' => $row->created_at?->toIso8601String(),
                'last_seen_at' => $row->last_seen_at?->toIso8601String(),
                'current' => hash_equals($row->session_hash, $currentHash),
                'revoke_url' => route('account.security.sessions.destroy', $row->id, false),
            ])->all(),
            'accessEntries' => $log,
            'securityUrls' => [
                'start' => route('account.security.two-factor.start', [], false),
                'confirm' => route('account.security.two-factor.confirm', [], false),
                'disable' => route('account.security.two-factor.disable', [], false),
                'recovery' => route('account.security.recovery-codes', [], false),
                'revoke_others' => route('account.security.sessions.others', [], false),
            ],
        ])->toResponse($request)->header('Cache-Control', 'no-store, private');
    }

    public function start(Request $request): RedirectResponse
    {
        $request->validate(['current_password' => ['required', 'current_password']]);
        $this->twoFactor->begin($request->user());

        return redirect()->route('account.security');
    }

    public function confirm(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);
        $codes = $this->twoFactor->confirm($request->user(), $data['code']);
        if ($codes === null) {
            throw ValidationException::withMessages(['code' => __('The code is not valid.')]);
        }
        AccessLog::record(AccessLog::TWOFACTOR_ENABLED, $request->user(), $request);

        return redirect()->route('account.security')->with('recovery_codes', $codes)->with('status', __('Two-factor authentication is on.'));
    }

    public function disable(Request $request): RedirectResponse
    {
        $request->validate(['current_password' => ['required', 'current_password']]);
        if ($this->instance->twoFactorRequired()) {
            throw ValidationException::withMessages(['current_password' => __('Your administrator requires two-factor authentication.')]);
        }
        $this->twoFactor->disable($request->user());
        AccessLog::record(AccessLog::TWOFACTOR_DISABLED, $request->user(), $request, ['actor' => 'self']);

        return redirect()->route('account.security')->with('status', __('Two-factor authentication is off.'));
    }

    public function regenerateCodes(Request $request): RedirectResponse
    {
        $request->validate(['current_password' => ['required', 'current_password']]);
        abort_unless($this->twoFactor->enabled($request->user()), 404);

        return redirect()->route('account.security')->with('recovery_codes', $this->twoFactor->issueRecoveryCodes($request->user()));
    }

    public function revokeSession(Request $request, string $session): RedirectResponse
    {
        $row = $request->user()->userSessions()->whereKey($session)->firstOrFail();
        if (hash_equals($row->session_hash, UserSessions::hash($request->session()->getId()))) {
            throw ValidationException::withMessages(['session' => __('This is the session you are using. Sign out instead.')]);
        }
        $this->sessions->revoke($row);
        AccessLog::record(AccessLog::SESSION_REVOKED, $request->user(), $request, ['device' => $row->device]);

        return redirect()->route('account.security')->with('status', __('Session revoked.'));
    }

    public function revokeOthers(Request $request): RedirectResponse
    {
        $count = $this->sessions->revokeOthers($request->user(), $request->session()->getId());
        AccessLog::record(AccessLog::SESSION_REVOKED, $request->user(), $request, ['count' => $count]);

        return redirect()->route('account.security')->with('status', trans_choice('{0} No other session was open.|{1} One session revoked.|[2,*] :count sessions revoked.', $count));
    }
}
