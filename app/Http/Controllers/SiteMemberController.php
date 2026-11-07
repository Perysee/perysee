<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateMemberRoleRequest;
use App\Models\SiteInvitation;
use App\Models\User;
use App\Models\Website;
use App\Support\SiteTeam;
use App\Support\Ui;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Response;

class SiteMemberController extends Controller
{
    /** Every member sees who has access; only owners see the pending invitations and the controls. */
    public function index(Request $request, Website $website): Response
    {
        Gate::authorize('view', $website);
        abort_if($website->deleting_at, 404);
        $manage = Gate::allows('manageMembers', $website);
        $members = $website->members()->orderByRaw("case site_members.role when 'owner' then 0 else 1 end")->orderBy('users.name')->get()
            ->map(fn (User $member): array => [
                'id' => $member->id, 'name' => $member->name, 'email' => $member->email, 'role' => $member->pivot->role, 'you' => $member->is($request->user()),
                'update_url' => $manage ? route('sites.team.update', [$website, $member->id]) : null,
                'remove_url' => $manage ? route('sites.team.destroy', [$website, $member->id]) : null,
            ])->all();
        $invitations = ! $manage ? [] : $website->invitations()->pending()->with('inviter:id,name')->orderBy('email')->get()
            ->map(fn (SiteInvitation $invitation): array => [
                'id' => $invitation->id, 'email' => $invitation->email, 'role' => $invitation->role, 'expires_at' => $invitation->expires_at->toIso8601String(),
                'invited_by' => $invitation->inviter?->name, 'sent' => $invitation->last_sent_at !== null,
                'resend_url' => route('sites.invitations.resend', [$website, $invitation->id]),
                'revoke_url' => route('sites.invitations.revoke', [$website, $invitation->id]),
            ])->all();

        return Ui::render('sites.team', [
            'website' => $website, 'canManage' => $manage, 'members' => $members, 'invitations' => $invitations,
            'inviteUrl' => $manage ? route('sites.invitations.store', $website) : null,
            'leaveUrl' => route('sites.team.leave', $website),
            // Shown once, right after an invitation was created or resent without a working mailer.
            'invitationLink' => $manage ? $request->session()->get('invitation_link') : null,
            'invitationEmail' => $manage ? $request->session()->get('invitation_email') : null,
        ]);
    }

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
