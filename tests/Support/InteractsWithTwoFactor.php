<?php

namespace Tests\Support;

use App\Models\User;
use App\Models\UserTwoFactor;
use App\Support\TwoFactor;
use PragmaRX\Google2FA\Google2FA;

trait InteractsWithTwoFactor
{
    /** Gives the account a confirmed second factor and returns [secret, recovery codes]. */
    protected function enableTwoFactor(User $user): array
    {
        $row = app(TwoFactor::class)->begin($user);
        $secret = $row->secret;
        $codes = app(TwoFactor::class)->confirm($user, $this->totp($secret));
        // Leave the current step unspent so a test can use it.
        UserTwoFactor::whereKey($user->id)->update(['last_step' => null]);

        return [$secret, $codes];
    }

    protected function totp(string $secret, int $stepOffset = 0): string
    {
        $google = new Google2FA;

        return $google->oathTotp($secret, $google->getTimestamp() + $stepOffset);
    }
}
