<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AccessLogEntry;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * The single writer of the access log: sign-ins, two-factor changes, accepted invitations, created share links,
 * revoked sessions and storage budget decisions of the back office. It never concerns the visitors of a measured site.
 *
 * Privacy: the address is truncated before it is stored (/24 for IPv4, /48 for IPv6), the browser is reduced to a
 * short label, and a failed sign-in keeps the typed address only when it belongs to an existing account.
 */
final class AccessLog
{
    public const RETENTION_DAYS = 90;

    public const LOGIN_SUCCESS = 'login.success';

    public const LOGIN_FAILED = 'login.failed';

    public const TWOFACTOR_ENABLED = 'twofactor.enabled';

    public const TWOFACTOR_DISABLED = 'twofactor.disabled';

    public const INVITATION_ACCEPTED = 'invitation.accepted';

    public const SHARE_CREATED = 'share.created';

    public const SESSION_REVOKED = 'session.revoked';

    /** The storage budget or its mode changed, `auto` included (ADR 011). */
    public const STORAGE_SETTINGS = 'storage.settings';

    /** An administrator agreed to the next step of the adaptive retention. */
    public const STORAGE_AGREED = 'storage.agreed';

    public const EVENTS = [
        self::LOGIN_SUCCESS, self::LOGIN_FAILED, self::TWOFACTOR_ENABLED, self::TWOFACTOR_DISABLED,
        self::INVITATION_ACCEPTED, self::SHARE_CREATED, self::SESSION_REVOKED, self::STORAGE_SETTINGS, self::STORAGE_AGREED,
    ];

    /**
     * @param  array<string, scalar|null>  $context  Short, non-secret facts (never a token, a code or a typed password).
     */
    public static function record(string $event, ?User $user, ?Request $request = null, array $context = [], ?string $emailTried = null): void
    {
        if (! in_array($event, self::EVENTS, true)) {
            throw new \InvalidArgumentException("Unknown access log event \"{$event}\".");
        }
        try {
            AccessLogEntry::create([
                'user_id' => $user?->getKey(),
                'event' => $event,
                'email_tried' => $user && $emailTried !== null ? mb_substr($emailTried, 0, 255) : null,
                'ip' => self::truncateIp($request?->ip()),
                'device' => $request ? self::deviceLabel($request->userAgent()) : null,
                'context' => $context ?: null,
                'created_at' => now('UTC'),
            ]);
        } catch (\Throwable $exception) {
            // The journal must never turn a sign-in or a share link into an error page.
            report($exception);
        }
    }

    /** IPv4 keeps its first three bytes (/24), IPv6 its first six (/48). Anything else is dropped. */
    public static function truncateIp(?string $ip): ?string
    {
        if ($ip === null || ($binary = @inet_pton($ip)) === false) {
            return null;
        }
        if (strlen($binary) === 16 && str_starts_with($binary, "\0\0\0\0\0\0\0\0\0\0\xff\xff")) {
            $binary = substr($binary, 12);
        }
        $keep = strlen($binary) === 4 ? 3 : 6;

        return inet_ntop(substr($binary, 0, $keep).str_repeat("\0", strlen($binary) - $keep));
    }

    /** "Firefox on Linux": enough to recognise a device, nothing that identifies a browser build. */
    public static function deviceLabel(?string $userAgent): string
    {
        $agent = (string) $userAgent;
        $browser = match (true) {
            str_contains($agent, 'Edg/') || str_contains($agent, 'EdgA/') || str_contains($agent, 'EdgiOS/') => 'Edge',
            str_contains($agent, 'OPR/') || str_contains($agent, 'Opera') => 'Opera',
            str_contains($agent, 'Firefox/') || str_contains($agent, 'FxiOS/') => 'Firefox',
            str_contains($agent, 'Chrome/') || str_contains($agent, 'CriOS/') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            default => null,
        };
        $system = match (true) {
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'iPhone') || str_contains($agent, 'iPad') || str_contains($agent, 'iPod') => 'iOS',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Mac OS X') || str_contains($agent, 'Macintosh') => 'macOS',
            str_contains($agent, 'CrOS') => 'ChromeOS',
            str_contains($agent, 'Linux') => 'Linux',
            default => null,
        };

        return match (true) {
            $browser && $system => "{$browser} on {$system}",
            (bool) $browser => $browser,
            (bool) $system => $system,
            default => 'Unknown device',
        };
    }
}
