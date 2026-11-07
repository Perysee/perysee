<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use App\Models\UserTwoFactor;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;

/**
 * Time-based one-time passwords (RFC 6238) and their recovery codes.
 *
 * Nothing here signs anyone in: the login controller decides what a valid second factor allows. The secret is
 * encrypted with the application key, a time step is accepted once only, and a recovery code is stored as a keyed
 * hash and spent on first use.
 */
final class TwoFactor
{
    public const RECOVERY_CODES = 8;

    private const ALPHABET = 'ABCDEFGHJKMNPQRSTVWXYZ23456789';

    public function __construct(private readonly Google2FA $google = new Google2FA)
    {
        $this->google->setWindow(1);
    }

    public function enabled(User $user): bool
    {
        return UserTwoFactor::whereKey($user->getKey())->whereNotNull('confirmed_at')->exists();
    }

    public function pending(User $user): ?UserTwoFactor
    {
        return UserTwoFactor::whereKey($user->getKey())->whereNull('confirmed_at')->first();
    }

    /** Starts (or restarts) an enrolment with a fresh secret. A confirmed enrolment is never replaced this way. */
    public function begin(User $user): UserTwoFactor
    {
        return DB::transaction(function () use ($user): UserTwoFactor {
            $current = UserTwoFactor::whereKey($user->getKey())->lockForUpdate()->first();
            if ($current?->confirmed_at) {
                return $current;
            }
            $secret = $this->google->generateSecretKey(32);
            if ($current) {
                $current->forceFill(['secret' => $secret, 'last_step' => null, 'created_at' => now('UTC')])->save();

                return $current;
            }

            return UserTwoFactor::create(['user_id' => $user->getKey(), 'secret' => $secret, 'created_at' => now('UTC')]);
        });
    }

    public function otpauthUri(User $user, string $secret): string
    {
        return $this->google->getQRCodeUrl((string) config('app.name', 'Perysee'), $user->email, $secret);
    }

    /** The QR code as an SVG drawn here: the secret is never sent to a third party. Null when it cannot be drawn. */
    public function qrSvg(string $uri): ?string
    {
        try {
            $svg = (new Writer(new ImageRenderer(new RendererStyle(200, 2), new SvgImageBackEnd)))->writeString($uri);

            return preg_replace('/^<\?xml[^>]*\?>\s*/', '', $svg);
        } catch (\Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /** The key as shown to a person typing it in: groups of four. */
    public function groupedSecret(string $secret): string
    {
        return trim(chunk_split($secret, 4, ' '));
    }

    /**
     * Confirms a pending enrolment with a first valid code and returns the recovery codes (shown once), or null
     * when there is nothing to confirm or the code is wrong.
     *
     * @return list<string>|null
     */
    public function confirm(User $user, string $code): ?array
    {
        return DB::transaction(function () use ($user, $code): ?array {
            $row = UserTwoFactor::whereKey($user->getKey())->whereNull('confirmed_at')->lockForUpdate()->first();
            $step = $row ? $this->matchStep($row, $code) : null;
            if ($step === null) {
                return null;
            }
            $row->forceFill(['confirmed_at' => now('UTC'), 'last_step' => $step])->save();

            return $this->issueRecoveryCodes($user);
        });
    }

    public function disable(User $user): void
    {
        DB::transaction(function () use ($user): void {
            DB::table('user_recovery_codes')->where('user_id', $user->getKey())->delete();
            DB::table('user_two_factor')->where('user_id', $user->getKey())->delete();
        });
    }

    /**
     * Checks the second factor of a sign-in: a code from the application or a recovery code. The check and the
     * spending of the code happen under a row lock, so the same code cannot succeed twice, even in parallel.
     *
     * @return 'totp'|'recovery_code'|null The method that matched, or null.
     */
    public function verifyChallenge(User $user, string $input): ?string
    {
        return DB::transaction(function () use ($user, $input): ?string {
            $row = UserTwoFactor::whereKey($user->getKey())->whereNotNull('confirmed_at')->lockForUpdate()->first();
            if (! $row) {
                return null;
            }
            if ($this->looksLikeTotp($input)) {
                $step = $this->matchStep($row, $input);
                if ($step === null) {
                    return null;
                }
                $row->forceFill(['last_step' => $step])->save();

                return 'totp';
            }

            return $this->spendRecoveryCode($user, $input) ? 'recovery_code' : null;
        });
    }

    /** @return list<string> New codes, formatted for display. The previous ones stop working. */
    public function issueRecoveryCodes(User $user): array
    {
        $codes = [];
        $rows = [];
        for ($index = 0; $index < self::RECOVERY_CODES; $index++) {
            $code = '';
            for ($position = 0; $position < 10; $position++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $codes[] = substr($code, 0, 5).'-'.substr($code, 5);
            $rows[] = ['user_id' => $user->getKey(), 'code_hash' => $this->hashCode($user, $code), 'used_at' => null];
        }
        DB::table('user_recovery_codes')->where('user_id', $user->getKey())->delete();
        DB::table('user_recovery_codes')->insert($rows);

        return $codes;
    }

    public function remainingRecoveryCodes(User $user): int
    {
        return DB::table('user_recovery_codes')->where('user_id', $user->getKey())->whereNull('used_at')->count();
    }

    private function looksLikeTotp(string $input): bool
    {
        return (bool) preg_match('/^\d{6}$/', preg_replace('/\s+/', '', $input) ?? '');
    }

    /** The accepted time step of a code, or null. A step at or below the last accepted one is a replay. */
    private function matchStep(UserTwoFactor $row, string $code): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (! preg_match('/^\d{6}$/', $code)) {
            return null;
        }
        try {
            $secret = $row->secret;
        } catch (DecryptException) {
            // The application key changed: the secret is lost, only a recovery code can still sign this account in.
            return null;
        }
        // The library's own "newer than" check answers `true` on a first use instead of the step, so the steps of
        // the window (one before, now, one after) are compared here. Every step is computed: no early exit.
        $now = $this->google->getTimestamp();
        $matched = null;
        for ($step = $now - 1; $step <= $now + 1; $step++) {
            $fresh = $row->last_step === null || $step > $row->last_step;
            if (hash_equals($this->google->oathTotp($secret, $step), $code) && $fresh) {
                $matched = $step;
            }
        }

        return $matched;
    }

    private function spendRecoveryCode(User $user, string $input): bool
    {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $input) ?? '');
        if (strlen($code) !== 10) {
            return false;
        }
        $id = DB::table('user_recovery_codes')->where('user_id', $user->getKey())->where('code_hash', $this->hashCode($user, $code))->whereNull('used_at')->value('id');

        return $id !== null && DB::table('user_recovery_codes')->where('id', $id)->whereNull('used_at')->update(['used_at' => now('UTC')]) === 1;
    }

    /** Keyed with the application key: a leaked table cannot be brute-forced offline without it. */
    private function hashCode(User $user, string $code): string
    {
        return hash_hmac('sha256', $user->getKey().'|'.$code, (string) config('app.key'));
    }
}
