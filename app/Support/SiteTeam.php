<?php

namespace App\Support;

use App\Models\User;
use App\Models\Website;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Changes to who can access a site. Each one runs under a lock on the site row, so two owners demoting or removing
 * each other at the same moment cannot leave the site without an owner.
 */
class SiteTeam
{
    public function changeRole(Website $website, User $member, string $role): void
    {
        $this->locked($website, $member, function () use ($website, $member, $role): void {
            if ($role !== 'owner') {
                $this->ensureAnotherOwner($website, $member);
            }
            $website->members()->updateExistingPivot($member->id, ['role' => $role]);
        });
    }

    public function remove(Website $website, User $member): void
    {
        $this->locked($website, $member, function () use ($website, $member): void {
            $this->ensureAnotherOwner($website, $member);
            $website->members()->detach($member->id);
        });
    }

    private function locked(Website $website, User $member, \Closure $change): void
    {
        DB::transaction(function () use ($website, $member, $change): void {
            $locked = Website::whereKey($website->id)->lockForUpdate()->firstOrFail();
            abort_if($locked->deleting_at, 409);
            abort_if(! $locked->members()->whereKey($member->id)->exists(), 404);
            $change();
        });
    }

    /** Refuses when the member is an owner and the only one. */
    private function ensureAnotherOwner(Website $website, User $member): void
    {
        $owners = $website->members()->wherePivot('role', 'owner');
        if ((clone $owners)->whereKey($member->id)->exists() && (clone $owners)->count() < 2) {
            throw ValidationException::withMessages(['member' => __('A site needs at least one owner.')]);
        }
    }
}
