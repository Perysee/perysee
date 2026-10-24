<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Website;

class WebsitePolicy
{
    public function view(User $user, Website $website): bool
    {
        return $website->members()->whereKey($user->id)->exists();
    }

    public function update(User $user, Website $website): bool
    {
        return $website->members()->whereKey($user->id)->wherePivot('role', 'owner')->exists();
    }
}
