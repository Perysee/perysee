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

    /** Public share links are managed by a site owner only. */
    public function share(User $user, Website $website): bool
    {
        return $website->members()->whereKey($user->id)->wherePivot('role', 'owner')->exists();
    }

    /** Read API tokens are managed by a site owner only; an instance administrator has no implicit access. */
    public function manageApiTokens(User $user, Website $website): bool
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
