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

    /** Inviting, changing roles and removing members: owners only, an instance administrator has no implicit access. */
    public function manageMembers(User $user, Website $website): bool
    {
        return $this->update($user, $website);
    }

    public function leave(User $user, Website $website): bool
    {
        return $this->view($user, $website);
    }
}
