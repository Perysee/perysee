<?php

namespace App\Models;

use App\Analytics\Reporting\ReportSections;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;

/**
 * A read-only public link to some reports of one site. Only the SHA-256 of the token is stored.
 */
class ShareLink extends Model
{
    /** Sections a link can expose, and the dimension reports each one allows. */
    public const SECTIONS = ReportSections::SECTIONS;

    protected $guarded = [];

    protected $hidden = ['token_hash', 'password_hash'];

    protected function casts(): array
    {
        return ['sections' => 'array', 'expires_at' => 'datetime', 'revoked_at' => 'datetime', 'last_used_at' => 'datetime'];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Creates a link and returns it with the raw token, which exists nowhere else.
     *
     * @param  list<string>  $sections
     * @return array{0: ShareLink, 1: string}
     */
    public static function issue(Website $website, ?User $creator, string $label, array $sections, ?string $password, mixed $expiresAt): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $link = new self([
            'website_id' => $website->id,
            'created_by' => $creator?->id,
            'token_hash' => self::hashToken($token),
            'token_hint' => substr($token, 0, 6),
            'label' => $label,
            'sections' => array_values($sections),
            'password_hash' => $password !== null && $password !== '' ? Hash::make($password) : null,
            'expires_at' => $expiresAt,
        ]);
        $link->save();

        return [$link, $token];
    }

    /** Dimension reports the link may serve. */
    public function dimensions(): array
    {
        return ReportSections::dimensions($this->sections ?? []);
    }

    public function allows(string $section): bool
    {
        return in_array($section, $this->sections ?? [], true);
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
