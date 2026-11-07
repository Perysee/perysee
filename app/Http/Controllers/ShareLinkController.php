<?php

namespace App\Http\Controllers;

use App\Models\ShareLink;
use App\Models\Website;
use App\Modules\Events\DomainEvents;
use App\Modules\Events\ShareLinkCreated;
use App\Modules\Events\ShareLinkRevoked;
use App\Support\AccessLog;
use App\Support\Ui;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Inertia\Response;

class ShareLinkController extends Controller
{
    private function authorizeSharing(Website $website): void
    {
        Gate::authorize('share', $website);
        abort_if($website->deleting_at || $website->is_demo, 404);
    }

    public function index(Request $request, Website $website): Response
    {
        $this->authorizeSharing($website);
        $links = $website->shareLinks()->latest('id')->get()->map(fn (ShareLink $link): array => [
            'id' => $link->id,
            'label' => $link->label,
            'hint' => $link->token_hint,
            'sections' => $link->sections,
            'protected' => $link->password_hash !== null,
            'status' => $link->status(),
            'expires_at' => $link->expires_at?->toIso8601String(),
            'revoked_at' => $link->revoked_at?->toIso8601String(),
            'last_used_at' => $link->last_used_at?->toIso8601String(),
            'use_count' => $link->use_count,
            'created_at' => $link->created_at?->toIso8601String(),
            'revoke_url' => route('sites.sharing.destroy', [$website, $link], false),
            'update_url' => route('sites.sharing.update', [$website, $link], false),
        ])->all();

        return Ui::render('sites.sharing', [
            'website' => $website,
            'shareLinks' => $links,
            'sectionOptions' => array_keys(ShareLink::SECTIONS),
            'created' => $request->session()->get('share_created'),
        ]);
    }

    public function store(Request $request, Website $website): RedirectResponse
    {
        $this->authorizeSharing($website);
        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:120'],
            'sections' => ['required', 'array', 'min:1'],
            'sections.*' => ['string', Rule::in(array_keys(ShareLink::SECTIONS)), 'distinct'],
            'password' => ['nullable', 'string', 'min:8', 'max:128'],
            'expires_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
        ]);
        $expires = isset($data['expires_on']) ? Carbon::parse($data['expires_on'], $website->timezone)->endOfDay()->utc() : null;
        [$link, $token] = ShareLink::issue($website, $request->user(), $data['label'] ?? '', $data['sections'], $data['password'] ?? null, $expires);
        // Module events (ADR 005): the token, its hint and the label never leave the Core.
        $events = app(DomainEvents::class);
        if ($events->listening(ShareLinkCreated::class)) {
            $events->publishAfterResponse(new ShareLinkCreated($website->id, $link->id, array_values($data['sections']), $link->password_hash !== null, $expires?->toIso8601String()));
        }
        AccessLog::record(AccessLog::SHARE_CREATED, $request->user(), $request, ['site_id' => $website->id, 'link_id' => $link->id]);

        return redirect()->route('sites.sharing', $website)->with('share_created', ['url' => route('share.show', $token, false)]);
    }

    /** Sets or removes the password. Either change invalidates every session that unlocked the link. */
    public function update(Request $request, Website $website, string $link): RedirectResponse
    {
        $this->authorizeSharing($website);
        $model = $website->shareLinks()->findOrFail($link);
        $data = $request->validate(['password' => ['nullable', 'string', 'min:8', 'max:128'], 'remove_password' => ['sometimes', 'boolean']]);
        if (! empty($data['password'])) {
            $model->password_hash = Hash::make($data['password']);
        } elseif ($request->boolean('remove_password')) {
            $model->password_hash = null;
        } else {
            return redirect()->route('sites.sharing', $website);
        }
        $model->secret_version++;
        $model->save();

        return redirect()->route('sites.sharing', $website)->with('status', __('Link password updated.'));
    }

    public function destroy(Website $website, string $link): RedirectResponse
    {
        $this->authorizeSharing($website);
        $model = $website->shareLinks()->findOrFail($link);
        $wasActive = $model->revoked_at === null;
        $model->revoked_at ??= now();
        $model->save();
        $events = app(DomainEvents::class);
        if ($wasActive && $events->listening(ShareLinkRevoked::class)) {
            $events->publishAfterResponse(new ShareLinkRevoked($website->id, $model->id));
        }

        return redirect()->route('sites.sharing', $website)->with('status', __('Link revoked.'));
    }
}
