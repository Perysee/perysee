<?php

namespace App\Http\Controllers;

use App\Analytics\InstanceHealth;
use App\Analytics\Reporting\Reports;
use App\Models\Website;
use App\Support\Ui;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Response;

class WorkspaceController extends Controller
{
    public function dashboard(Request $request, Reports $reports): Response
    {
        $sites = $request->user()->websites()->whereNull('deleting_at')->orderBy('name')->paginate(20);
        $summaries = [];
        $charts = [];
        $sitePeriods = [];
        foreach ($sites as $site) {
            $period = $reports->period($request, $site);
            $sitePeriods[$site->id] = ['from' => $period['from'], 'to' => $period['to'], 'timezone' => $site->timezone];
            $summaries[$site->id] = $reports->overview($site, $period);
            $charts[$site->id] = $reports->series($site, $period);
        }

        return Ui::render('dashboard', compact('sites', 'summaries', 'charts', 'sitePeriods'));
    }

    public function pixels(): Response
    {
        return Ui::render('placeholder', ['title' => 'Pixels', 'description' => 'Manage lightweight tracking pixels from one place.', 'message' => 'Pixel tracking will be available in a future release. You can already measure pageviews with Lueur.', 'icon' => 'grid-dots', 'website' => null]);
    }

    public function settings(Request $request): Response
    {
        abort_unless($request->user()->is_admin, 403);

        return Ui::render('settings', ['settings' => [
            'Application name' => config('app.name'),
            'Application address' => config('app.url'),
            'Instance timezone' => config('app.timezone'),
            'Raw event retention' => app(InstanceHealth::class)->get('raw_retention_days', config('perysee.raw_retention_days')).' days',
        ]]);
    }

    public function sitePage(Request $request, Website $website, string $section, Reports $reports): Response
    {
        Gate::authorize('view', $website);
        abort_if($website->deleting_at, 404);
        if ($section === 'pixels') {
            return Ui::render('placeholder', ['title' => 'Pixels', 'description' => 'Tracking pixels', 'message' => 'Available in a future release.', 'icon' => 'grid-dots', 'website' => $website]);
        }
        $dimension = ['sources' => 'source', 'campaigns' => 'campaign', 'devices' => 'device', 'events' => 'event', 'pages' => 'path', 'countries' => 'country'][$section] ?? 'path';
        $period = $reports->period($request, $website);
        $technologyCoverage = [];
        if ($section === 'devices') {
            $request->validate(['dimension' => ['sometimes', 'in:device,device_brand,browser']]);
            $dimension = $request->input('dimension', 'device');
            $technologyCoverage = $reports->clientCoverage($website, $period);
        }

        $mapPeriod = $period;
        unset($mapPeriod['filters']['country']);

        return Ui::render('sites.show', ['website' => $website, 'period' => $period, 'report' => $reports->overview($website, $period), 'series' => $section === 'sessions' ? $reports->series($website, $period) : [], 'dimensions' => [], 'dimension' => $dimension, 'technologyCoverage' => $technologyCoverage, 'countryContext' => $section === 'countries' ? $reports->dimensionReport($website, $mapPeriod, 'country')['map'] : [], 'recent' => $section === 'realtime' ? $reports->recent($website) : [], 'section' => $section, 'dimensionReport' => in_array($section, ['sessions', 'realtime'], true) ? null : $reports->dimensionReport($website, $period, $dimension, $request->input('value')), 'receptionTotals' => $section === 'realtime' ? $reports->receptionTotals($website) : null]);
    }
}
