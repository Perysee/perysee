<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use App\Models\UserSession;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;

/**
 * The list of browsers signed in to each account, kept in our own table so that it works with every session
 * driver (the installer chooses `file`, which cannot be listed by user).
 *
 * The id of a session is a bearer secret: the table keeps its SHA-256 (to find the current session) and the id
 * itself encrypted with the application key (to destroy that session when asked). It is decrypted only then.
 */
final class UserSessions
{
    /** The last-seen time is rewritten at most this often per session: a page view costs no query in between. */
    public const TOUCH_SECONDS = 600;

    /** At most this many sessions are destroyed by one action. */
    public const REVOKE_LIMIT = 200;

    /** Added to the session lifetime before a row that nobody renewed is purged. */
    private const PURGE_MARGIN_MINUTES = 60;

    public static function hash(string $sessionId): string
    {
        return hash('sha256', $sessionId);
    }

    /** Records the session of the current request, or renews its row. Idempotent. */
    public function register(User $user, Request $request): void
    {
        $session = $request->session();
        $hash = self::hash($session->getId());
        $now = now('UTC');
        $previous = $session->get('tracked_session');
        // The id changes at sign-in and password change: the row of the old id is of no use any more.
        if (is_array($previous) && $previous['hash'] !== $hash) {
            UserSession::where('session_hash', $previous['hash'])->where('user_id', $user->getKey())->delete();
        }
        $row = UserSession::where('session_hash', $hash)->first();
        if ($row && $row->user_id !== $user->getKey()) {
            $row->delete();
            $row = null;
        }
        if ($row) {
            $row->forceFill(['last_seen_at' => $now, 'ip' => AccessLog::truncateIp($request->ip())])->save();
        } else {
            UserSession::create([
                'user_id' => $user->getKey(),
                'session_hash' => $hash,
                'session_id_enc' => Crypt::encryptString($session->getId()),
                'device' => AccessLog::deviceLabel($request->userAgent()),
                'ip' => AccessLog::truncateIp($request->ip()),
                'created_at' => $now,
                'last_seen_at' => $now,
            ]);
        }
        $session->put('tracked_session', ['hash' => $hash, 'at' => $now->timestamp]);
    }

    /** True when the row of the current session has to be written or renewed (no query). */
    public function due(Request $request): bool
    {
        $tracked = $request->session()->get('tracked_session');

        return ! is_array($tracked)
            || $tracked['hash'] !== self::hash($request->session()->getId())
            || now('UTC')->timestamp - (int) $tracked['at'] >= self::TOUCH_SECONDS;
    }

    /** Removes the row and destroys the session it stands for. Never the session of the caller. */
    public function revoke(UserSession $row): void
    {
        $this->destroy($row);
        $row->delete();
    }

    /**
     * Revokes every session of the account except the given one.
     *
     * @return int Number of sessions revoked.
     */
    public function revokeOthers(User $user, ?string $keepSessionId = null): int
    {
        $query = UserSession::where('user_id', $user->getKey());
        if ($keepSessionId !== null) {
            $query->where('session_hash', '!=', self::hash($keepSessionId));
        }
        $count = 0;
        foreach ($query->orderBy('id')->limit(self::REVOKE_LIMIT)->get() as $row) {
            $this->revoke($row);
            $count++;
        }

        return $count;
    }

    /**
     * Rows nobody renewed for longer than a session can live, in resumable batches.
     *
     * @return int Rows removed.
     */
    public function purgeExpired(int $limit): int
    {
        $cutoff = now('UTC')->subMinutes((int) config('session.lifetime', 120) + self::PURGE_MARGIN_MINUTES);
        $ids = DB::table('user_sessions')->where(fn ($query) => $query->where('last_seen_at', '<', $cutoff)->orWhereNull('last_seen_at'))->orderBy('id')->limit($limit)->pluck('id');

        return $ids->isEmpty() ? 0 : DB::table('user_sessions')->whereIn('id', $ids)->delete();
    }

    private function destroy(UserSession $row): void
    {
        try {
            Session::getHandler()->destroy(Crypt::decryptString($row->session_id_enc));
        } catch (DecryptException) {
            // The application key changed: the id cannot be recovered, the session expires on its own.
        }
    }
}
