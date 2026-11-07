<?php

namespace App\Http\Controllers;

use App\Http\Requests\AcceptInvitationRequest;
use App\Models\SiteInvitation;
use App\Models\User;
use App\Models\Website;
use App\Support\Ui;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Opening the link only shows the invitation; the token is consumed by the POST. Every token that is not pending
 * (unknown, expired, revoked, used) gets the same 404.
 */
class InvitationAcceptanceController extends Controller
{
    public function show(Request $request, string $token): Response
    {
        $this->throttle($request);
        $invitation = SiteInvitation::findPending($token);
        abort_if(! $invitation || $invitation->website->deleting_at, 404);

        return Ui::render('invitation', ['invitation' => [
            'site' => $invitation->website->name,
            'domain' => $invitation->website->domain,
            'role' => $invitation->role,
            'email' => $invitation->email,
            'accept_url' => route('invitations.accept', $token, false),
        ]])->toResponse($request);
    }

    public function accept(AcceptInvitationRequest $request, string $token): RedirectResponse
    {
        $this->throttle($request);
        $website = DB::transaction(function () use ($request, $token): Website {
            $invitation = SiteInvitation::pending()->where('token_hash', SiteInvitation::hashToken($token))->lockForUpdate()->first();
            abort_if(! $invitation, 404);
            $website = Website::whereKey($invitation->website_id)->lockForUpdate()->first();
            abort_if(! $website || $website->deleting_at, 404);

            abort_if($request->user(), 403);
            $user = User::create(['name' => $request->validated('name'), 'email' => $invitation->email, 'password' => $request->validated('password')]);
            // The address counts as verified only when a message really reached it.
            if ($invitation->last_sent_at) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }
            $website->members()->attach($user, ['role' => $invitation->role]);
            $invitation->forceFill(['accepted_at' => now('UTC')])->save();
            Auth::login($user);

            return $website;
        });
        $request->session()->regenerate();

        return redirect()->route('sites.show', $website);
    }

    private function throttle(Request $request): void
    {
        $key = 'invitation-ip:'.$request->ip();
        abort_if(RateLimiter::tooManyAttempts($key, 10), 429, 'Too many attempts. Try again in a minute.');
        RateLimiter::hit($key, 60);
    }
}
