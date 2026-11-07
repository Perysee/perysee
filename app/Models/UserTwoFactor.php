<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The TOTP enrolment of one account. The secret is encrypted with the application key. */
class UserTwoFactor extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'user_two_factor';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $guarded = [];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return ['secret' => 'encrypted', 'confirmed_at' => 'datetime', 'last_step' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
