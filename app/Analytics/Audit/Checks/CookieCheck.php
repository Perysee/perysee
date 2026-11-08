<?php

declare(strict_types=1);

namespace App\Analytics\Audit\Checks;

use App\Analytics\Audit\AuditContext;
use App\Analytics\Audit\Finding;

/**
 * The cookie of the tracker and its session rules, read from `config('perysee.tracker')` (the numbers of lueur.js,
 * kept equal by TrackerConstantsTest). Only the `sessions` mode sets the cookie, so without such a site it is not
 * set at all.
 */
final class CookieCheck extends AbstractCheck
{
    public function id(): string
    {
        return 'measurement.cookie';
    }

    public function run(AuditContext $context): Finding
    {
        $idle = intdiv((int) config('perysee.tracker.session_idle_ms'), 60000);
        $lifetime = intdiv((int) config('perysee.tracker.session_lifetime_ms'), 3600000);
        $maxAge = (int) config('perysee.tracker.cookie_max_age_seconds');
        $sessions = count(array_filter($context->sites()['sites'], fn (array $site): bool => $site['tracking_mode'] === 'sessions'));
        $limit = $context->profile->cookieMaxSeconds;
        // The cookie is renewed during the session, so the longest it can last is the session lifetime.
        $longest = max($maxAge, intdiv((int) config('perysee.tracker.session_lifetime_ms'), 1000));
        $over = $sessions > 0 && $limit !== null && $longest > $limit;

        return $this->finding(
            $over ? Finding::ATTENTION : Finding::OK,
            __('Tracker cookie and session'),
            $sessions === 0
                ? __('no site uses the sessions mode, so the script sets no cookie (when it does: Max-Age :age min, session inactivity :idle min, session lifetime :hours h)', ['age' => round($maxAge / 60, 1), 'idle' => $idle, 'hours' => $lifetime])
                : __('Max-Age :age min, session inactivity :idle min, session lifetime :hours h', ['age' => round($maxAge / 60, 1), 'idle' => $idle, 'hours' => $lifetime]),
            $over
                ? __('The cookie can be renewed for longer than this profile allows.')
                : __('The cookie is renewed at each page view but never past the session lifetime, counted from its creation.'),
            $limit === null ? null : __('cookie of :days days at most', ['days' => round($limit / 86400, 1)]),
        );
    }
}
