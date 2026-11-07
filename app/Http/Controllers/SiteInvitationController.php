<?php

namespace App\Http\Controllers;

use App\Http\Requests\InviteMemberRequest;
use App\Mail\InstanceMailer;
use App\Mail\SiteInvitationMail;
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

    /**
     * With a real mailer the invitation is mailed. Otherwise, or if sending fails, the invitation is kept and its link
     * is handed to the owner once, as the raw token is not stored.
     */
    private function delivered(Website $website, SiteInvitation $invitation, string $token): RedirectResponse
    {
        $mailer = app(InstanceMailer::class);
        $url = route('invitations.show', $token);
        $back = redirect()->route('sites.team', $website);
        if ($mailer->configured()) {
            try {
                $mailer->send($invitation->email, new SiteInvitationMail($url, $website->name, $invitation->role, auth()->user()?->name, $invitation->expires_at->toFormattedDateString(), config('app.name')));
                $invitation->forceFill(['last_sent_at' => now('UTC')])->save();

                return $back->with('status', __('Invitation sent to :email.', ['email' => $invitation->email]));
            } catch (\Throwable) {
                // InstanceMailer already reported the failure, without any credential.
                $back->with('error', __('The e-mail could not be sent. Share the invitation link yourself.'));
            }
        }

        return $back->with('invitation_link', $url)->with('invitation_email', $invitation->email);
    }
}
