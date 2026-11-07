<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

#[Fillable(['website_id', 'email', 'role', 'token_hash', 'invited_by', 'expires_at', 'last_sent_at'])]
class SiteInvitation extends Model
{
    public const VALID_DAYS = 7;

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'accepted_at' => 'datetime', 'revoked_at' => 'datetime', 'last_sent_at' => 'datetime'];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now('UTC'));
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->revoked_at === null && $this->expires_at->isFuture();
    }

    /** The pending invitation for a raw token, looked up by the unique hash index. */
    public static function findPending(string $token): ?self
    {
        return static::pending()->where('token_hash', static::hashToken($token))->first();
    }

    /**
     * Creates the invitation of an e-mail to a site, or renews the pending one (one per site and e-mail).
     * The raw token is returned once and never stored.
     *
     * @return array{0: SiteInvitation, 1: string}
     */
    public static function issue(Website $website, string $email, string $role, ?User $by): array
    {
        $email = mb_strtolower(trim($email));

        return DB::transaction(function () use ($website, $email, $role, $by): array {
            Website::whereKey($website->id)->lockForUpdate()->firstOrFail();
            $invitation = static::pending()->where('website_id', $website->id)->where('email', $email)->first()
                ?? new static(['website_id' => $website->id, 'email' => $email, 'invited_by' => $by?->id]);
            $invitation->role = $role;
            $token = $invitation->rotate(save: false);
            $invitation->save();

            return [$invitation, $token];
        });
    }

    /** Replaces the token (the previous link stops working), restarts the validity and returns the new raw token. */
    public function rotate(bool $save = true): string
    {
        $token = bin2hex(random_bytes(32));
        $this->forceFill(['token_hash' => static::hashToken($token), 'expires_at' => now('UTC')->addDays(self::VALID_DAYS), 'last_sent_at' => null]);
        if ($save) {
            $this->save();
        }

        return $token;
    }
}
