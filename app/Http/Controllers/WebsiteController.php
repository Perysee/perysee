<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveWebsiteRequest;
use App\Models\Website;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class WebsiteController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:150']]);
        $search = trim($filters['q'] ?? '');
        $query = $request->user()->websites();
        if ($search !== '') {
            $query->where(function ($query) use ($search): void {
                $query->where('websites.name', 'like', '%'.$search.'%')->orWhere('domain', 'like', '%'.$search.'%');
            });
        }

        return view('sites.index', ['websites' => $query->orderBy('name')->paginate(20)->withQueryString(), 'search' => $search]);
    }

    public function create(): View
    {
        return view('sites.form', ['website' => new Website(['timezone' => 'UTC', 'allowed_domains' => [], 'tracking_mode' => 'pageviews', 'consent_required' => true])]);
    }

    public function store(SaveWebsiteRequest $request): RedirectResponse
    {
        $website = DB::transaction(function () use ($request): Website {
            $website = Website::create($request->siteAttributes());
            $website->members()->attach($request->user(), ['role' => 'owner']);

            return $website;
        });

        return redirect()->route('sites.show', $website)->with('status', __('Site created.'));
    }

    public function show(Website $website): View
    {
        Gate::authorize('view', $website);

        $recent = DB::table('events')->where('website_id', $website->id)->where('received_at', '>=', now('UTC')->subMinutes(15));
        $tracking = [
            'pageviews' => (clone $recent)->count(),
            'sessions' => (clone $recent)->whereNotNull('session_key')->distinct()->count('session_key'),
            'latest' => DB::table('events')->where('website_id', $website->id)->max('received_at'),
        ];

        return view('sites.show', compact('website', 'tracking'));
    }

    public function tracking(Website $website): View
    {
        Gate::authorize('view', $website);

        return view('sites.tracking', compact('website'));
    }

    public function edit(Website $website): View
    {
        Gate::authorize('update', $website);

        return view('sites.form', compact('website'));
    }

    public function update(SaveWebsiteRequest $request, Website $website): RedirectResponse
    {
        $website->update($request->siteAttributes());

        return redirect()->route('sites.show', $website)->with('status', __('Site updated.'));
    }
}
