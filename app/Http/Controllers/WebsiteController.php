<?php

namespace App\Http\Controllers;

use App\Analytics\Diagnostics\SiteActivity;
use App\Analytics\Reporting\Reports;
use App\Http\Requests\SaveWebsiteRequest;
use App\Models\Website;
use App\Support\Currencies;
use App\Support\Ui;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Response;

class WebsiteController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:150']]);
        $search = trim($filters['q'] ?? '');
        $query = $request->user()->websites()->whereNull('deleting_at');
        if ($search !== '') {
            $query->where(function ($query) use ($search): void {
                $query->where('websites.name', 'like', '%'.$search.'%')->orWhere('domain', 'like', '%'.$search.'%');
            });
        }

        $websites = $query->orderBy('name')->paginate(20)->withQueryString();
        SiteActivity::annotate($websites); // installation check: last event and silent flag

        return Ui::render('sites.index', ['websites' => $websites, 'search' => $search]);
    }

    public function create(): Response
    {
        return Ui::render('sites.form', ['website' => new Website(['timezone' => 'UTC', 'allowed_domains' => [], 'tracking_mode' => 'pageviews', 'consent_required' => true]), 'currencies' => Currencies::CODES]);
    }

    public function store(SaveWebsiteRequest $request): RedirectResponse
    {
        $website = DB::transaction(function () use ($request): Website {
            $website = Website::create($request->siteAttributes())->refresh();
            DB::table('site_measurement_periods')->insert(['website_id' => $website->id, 'started_at' => now('UTC'), 'mode' => $website->tracking_mode]);
            $website->members()->attach($request->user(), ['role' => 'owner']);

            return $website;
        });

        return redirect()->route('sites.show', $website)->with('status', __('Site created.'));
    }

    public function show(Request $request, Website $website, Reports $reports): Response|RedirectResponse
    {
        Gate::authorize('view', $website);
        abort_if($website->deleting_at, 404);
        $period = $reports->period($request, $website);
        if ($request->input('view') === 'pages' || $request->filled('value')) {
            return redirect()->route('sites.section', ['website' => $website, 'section' => 'pages'] + $request->only(['from', 'to', 'period', 'value']));
        }

        $props = ['website' => $website, 'period' => $period, 'report' => $reports->overview($website, $period), 'series' => $reports->series($website, $period), 'dimensions' => $reports->dimensions($website, $period, 'path', $request->input('value')), 'dimension' => 'path', 'breakdowns' => ['source' => $reports->dimensions($website, $period, 'source')]];
        if ($period['compare'] === 'previous') {
            $props['comparison'] = $reports->comparison($website, $period, $props['report']);
        }

        return Ui::render('sites.show', $props);
    }

    public function destroy(Request $request, Website $website): RedirectResponse
    {
        Gate::authorize('update', $website);
        $request->validate(['confirmation' => ['required', 'string', Rule::in([$website->domain])]]);
        DB::transaction(function () use ($website): void {
            $locked = Website::whereKey($website->id)->lockForUpdate()->firstOrFail();
            $locked->forceFill(['deleting_at' => $locked->deleting_at ?? now('UTC')])->save();
        });

        return redirect()->route('sites.index')->with('status', 'Collection stopped. Site data will be deleted in bounded batches by the scheduled maintenance.');
    }

    public function tracking(Website $website): Response
    {
        Gate::authorize('view', $website);
        abort_if($website->deleting_at, 404);

        return Ui::render('sites.tracking', compact('website'));
    }

    public function edit(Website $website): Response
    {
        Gate::authorize('update', $website);
        abort_if($website->deleting_at, 404);

        return Ui::render('sites.form', compact('website') + ['currencies' => Currencies::CODES]);
    }

    public function update(SaveWebsiteRequest $request, Website $website): RedirectResponse
    {
        DB::transaction(function () use ($request, $website): void {
            $locked = Website::whereKey($website->id)->lockForUpdate()->firstOrFail();
            $data = $request->siteAttributes();
            if (($locked->first_collected_at || DB::table('events')->where('website_id', $locked->id)->exists()) && $data['timezone'] !== $locked->timezone) {
                throw ValidationException::withMessages(['timezone' => 'The reporting timezone is fixed after the first collected event.']);
            }
            abort_if($locked->deleting_at, 409);
            if (isset($data['tracking_mode']) && $data['tracking_mode'] !== $locked->tracking_mode) {
                $changed = now('UTC')->format('Y-m-d H:i:s.v');
                DB::table('site_measurement_periods')->where('website_id', $locked->id)->whereNull('ended_at')->update(['ended_at' => $changed]);
                DB::table('site_measurement_periods')->insert(['website_id' => $locked->id, 'started_at' => $changed, 'mode' => $data['tracking_mode']]);
            }
            $locked->update($data);
        }, 3);

        return redirect()->route('sites.show', $website)->with('status', __('Site updated.'));
    }
}
