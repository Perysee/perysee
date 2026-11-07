<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One security event of the back office. Written by App\Support\AccessLog only; never edited. */
class AccessLogEntry extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'access_log';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['context' => 'array', 'created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
