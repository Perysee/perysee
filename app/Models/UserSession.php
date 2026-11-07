<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A browser signed in to an account. Only the hash of the session id is readable; the id itself is encrypted. */
class UserSession extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $hidden = ['session_hash', 'session_id_enc'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'last_seen_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
