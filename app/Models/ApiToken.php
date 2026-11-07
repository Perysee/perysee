<?php

namespace App\Models;

use App\Analytics\Reporting\ReportSections;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A read-only API credential bound to one site, with the scopes the owner chose. Only the SHA-256 of the secret
 * is stored; the secret itself is shown once, when the token is created.
 */
class ApiToken extends Model
{
    public const PREFIX = 'psy_';

    protected $guarded = [];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['scopes' => 'array', 'expires_at' => 'datetime', 'revoked_at' => 'datetime', 'last_used_at' => 'datetime', 'rate_per_minute' => 'integer'];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Creates a token and returns it with the raw secret, which exists nowhere else.
     *
     * @param  list<string>  $scopes
     * @return array{0: ApiToken, 1: string}
     */
    public static function issue(Website $website, ?User $creator, string $label, array $scopes, int $ratePerMinute, mixed $expiresAt): array
    {
        $secret = self::PREFIX.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $token = new self([
            'website_id' => $website->id,
            'created_by' => $creator?->id,
            'token_hash' => self::hashToken($secret),
            // The end of the secret: the prefix is the same for every token, so only the end tells two of them apart.
            'token_hint' => substr($secret, -6),
            'label' => $label,
            'scopes' => array_values($scopes),
            'rate_per_minute' => $ratePerMinute,
            'expires_at' => $expiresAt,
        ]);
        $token->save();

        return [$token, $secret];
    }

    public function allows(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? [], true);
    }

    /** Dimension reports the scopes of this token allow. */
    public function dimensions(): array
    {
        return ReportSections::dimensions(array_values(array_intersect($this->scopes ?? [], array_keys(ReportSections::SECTIONS))));
    }

    public function status(): string
    {
        return match (true) {
            $this->revoked_at !== null => 'revoked',
            $this->expires_at !== null && $this->expires_at->isPast() => 'expired',
            default => 'active',
        };
    }
}
