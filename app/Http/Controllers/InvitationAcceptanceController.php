<?php

namespace App\Http\Controllers;

use App\Http\Requests\AcceptInvitationRequest;
use App\Models\SiteInvitation;
use App\Models\User;
use App\Models\Website;
use App\Modules\Events\DomainEvents;
use App\Modules\Events\InvitationAccepted;
use App\Support\Ui;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Opening the link only shows the invitation; the token is consumed by the POST. Every token that is not pending
 * (unknown, expired, revoked, used) gets the same 404.
 */
class InvitationAcceptanceController extends Controller
{
    public function show(Request $request, string $token): Response
    {
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
        $joined = null;
        $outcome = DB::transaction(function () use ($request, $token, &$joined): Website|RedirectResponse {
            $invitation = SiteInvitation::pending()->where('token_hash', SiteInvitation::hashToken($token))->lockForUpdate()->first();
            abort_if(! $invitation, 404);
            $website = Website::whereKey($invitation->website_id)->lockForUpdate()->first();
            abort_if(! $website || $website->deleting_at, 404);

            // After five failures the invitation is closed to everyone, the invited person included: it has to be sent again.
            $failures = 'invitation-fail:'.$invitation->id;
            abort_if(RateLimiter::tooManyAttempts($failures, 5), 404);
            $user = $request->user();
            $created = false;
            if ($user) {
                // Never say which address was invited.
                if (mb_strtolower($user->email) !== $invitation->email) {
                    RateLimiter::hit($failures, 3600);
                    throw ValidationException::withMessages(['invitation' => __('This invitation is not valid for this account.')]);
                }
            } elseif (User::whereRaw('lower(email) = ?', [$invitation->email])->exists()) {
                // The holder of the link asks to sign in first; the page then returns here.
                $request->session()->put('url.intended', route('invitations.show', $token));

                return redirect()->route('login');
            } else {
                $user = User::create(['name' => $request->validated('name'), 'email' => $invitation->email, 'password' => $request->validated('password')]);
                $created = true;
                // The address counts as verified only when a message really reached it.
                if ($invitation->last_sent_at) {
                    $user->forceFill(['email_verified_at' => now()])->save();
                }
            }
            $current = $website->members()->whereKey($user->id)->first();
            if (! $current) {
                $website->members()->attach($user, ['role' => $invitation->role]);
            } elseif ($invitation->role === 'owner' && $current->pivot->role !== 'owner') {
                $website->members()->updateExistingPivot($user->id, ['role' => 'owner']);
            }
            $invitation->forceFill(['accepted_at' => now('UTC')])->save();
            $joined = [$website->id, $invitation->role, $created, $user->id];
            if (! $request->user()) {
                Auth::login($user);
            }

            return $website;
        });
        if ($outcome instanceof RedirectResponse) {
            return $outcome;
        }
        $request->session()->regenerate();
        // Module events (ADR 005): after the commit, delivered once the response is sent; ids only, no name or address.
        $events = app(DomainEvents::class);
        if ($joined !== null && $events->listening(InvitationAccepted::class)) {
            $events->publishAfterResponse(new InvitationAccepted(...$joined));
        }

        return redirect()->route('sites.show', $outcome);
    }
}
