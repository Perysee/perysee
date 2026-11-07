<?php

namespace App\Http\Controllers;

use App\Http\Requests\InviteMemberRequest;
use App\Models\SiteInvitation;
use App\Models\Website;
use Illuminate\Http\RedirectResponse;
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

    /** Hands the link to the owner: it is shown once, as the raw token is not kept. */
    private function delivered(Website $website, SiteInvitation $invitation, string $token): RedirectResponse
    {
        return redirect()->route('sites.team', $website)
            ->with('invitation_link', route('invitations.show', $token))
            ->with('invitation_email', $invitation->email);
    }
}
