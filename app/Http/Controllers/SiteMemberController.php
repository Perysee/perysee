<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateMemberRoleRequest;
use App\Models\Website;
use App\Support\SiteTeam;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SiteMemberController extends Controller
{
    public function update(UpdateMemberRoleRequest $request, Website $website, int $member, SiteTeam $team): RedirectResponse
    {
        $team->changeRole($website, $website->members()->whereKey($member)->firstOrFail(), $request->validated('role'));

        return redirect()->route('sites.team', $website)->with('status', __('Role updated.'));
    }

    public function destroy(Website $website, int $member, SiteTeam $team): RedirectResponse
    {
        Gate::authorize('manageMembers', $website);
        $team->remove($website, $website->members()->whereKey($member)->firstOrFail());

        return redirect()->route('sites.team', $website)->with('status', __('Member removed.'));
    }

    public function leave(Request $request, Website $website, SiteTeam $team): RedirectResponse
    {
        Gate::authorize('leave', $website);
        $team->remove($website, $request->user());

        return redirect()->route('sites.index')->with('status', __('You left the site.'));
    }
}
