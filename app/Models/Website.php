<?php

namespace App\Models;

use Database\Factories\WebsiteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['name', 'domain', 'allowed_domains', 'timezone', 'tracking_mode', 'consent_required', 'excluded_paths'])]
class Website extends Model
{
    /** @use HasFactory<WebsiteFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (Website $website): void {
            $website->public_id ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return ['allowed_domains' => 'array', 'consent_required' => 'boolean', 'is_demo' => 'boolean', 'excluded_paths' => 'array', 'first_collected_at' => 'datetime', 'deleting_at' => 'datetime'];
    }

    public function shareLinks(): HasMany
    {
        return $this->hasMany(ShareLink::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(SiteInvitation::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'site_members')->withPivot('role')->withTimestamps();
    }
}
