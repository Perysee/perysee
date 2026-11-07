<?php

namespace App\Http\Controllers;

use App\Http\Requests\InviteMemberRequest;
use App\Models\SiteInvitation;
use App\Models\Website;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;

class SiteInvitationController extends Controller
{
    public function store(InviteMemberRequest $request, Website $website): RedirectResponse
    {
        abort_if($website->deleting_at, 409);
        $key = 'invite:'.$request->user()->id;
        abort_if(RateLimiter::tooManyAttempts($key, 20), 429, 'Too many invitations. Try again later.');
        RateLimiter::hit($key, 3600);
        [$invitation, $token] = SiteInvitation::issue($website, $request->validated('email'), $request->validated('role'), $request->user());

        return $this->delivered($website, $invitation, $token);
    }

    public function resend(Request $request, Website $website, int $invitation): RedirectResponse
    {
        Gate::authorize('manageMembers', $website);
        abort_if($website->deleting_at, 409);
        $invitation = $website->invitations()->pending()->findOrFail($invitation);
        $key = 'invitation-resend:'.$invitation->id;
        abort_if(RateLimiter::tooManyAttempts($key, 1), 429, 'This invitation was just sent. Try again in a minute.');
        RateLimiter::hit($key, 60);

        return $this->delivered($website, $invitation, $invitation->rotate());
    }

    public function revoke(Website $website, int $invitation): RedirectResponse
    {
        Gate::authorize('manageMembers', $website);
        abort_if($website->deleting_at, 409);
        $website->invitations()->pending()->findOrFail($invitation)->forceFill(['revoked_at' => now('UTC')])->save();

        return redirect()->route('sites.team', $website)->with('status', __('Invitation revoked.'));
    }

    /** Hands the link to the owner: it is shown once, as the raw token is not kept. */
    private function delivered(Website $website, SiteInvitation $invitation, string $token): RedirectResponse
    {
        return redirect()->route('sites.team', $website)
            ->with('invitation_link', route('invitations.show', $token))
            ->with('invitation_email', $invitation->email);
    }
}
