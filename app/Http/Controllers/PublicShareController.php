<?php

namespace App\Http\Controllers;

use App\Analytics\Reporting\Reports;
use App\Models\ShareLink;
use App\Models\Website;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public, read-only view of a report. The site and the sections come only from the link; the request can
 * choose a period, a granularity and one dimension among those the link allows, and nothing else.
 */
class PublicShareController extends Controller
{
    public function show(Request $request, Reports $reports): Response
    {
        [$link, $site] = $this->context($request);
        $period = $this->period($request, $site, $reports);
        $dimension = $this->dimension($request, $link);
        $overview = $link->allows('overview');

        return Inertia::render('Share/Show', [
            'site' => ['name' => $site->name, 'domain' => $site->domain, 'timezone' => $site->timezone],
            'sections' => $link->sections,
            'period' => ['from' => $period['from'], 'to' => $period['to'], 'granularity' => $period['granularity']],
            'today' => now($site->timezone)->toDateString(),
            'report' => $overview ? $this->overview($reports, $site, $period) : null,
            'series' => $overview ? $reports->series($site, $period) : [],
            'dimensionOptions' => $link->dimensions(),
            'dimension' => $dimension,
            'dimensionReport' => $dimension !== null ? $this->dimensionReport($reports, $site, $period, $dimension) : null,
        ]);
    }

    public function api(Request $request, string $token, string $report, Reports $reports): JsonResponse
    {
        [$link, $site] = $this->context($request);
        $period = $this->period($request, $site, $reports);
        if ($report === 'dimensions') {
            $dimension = $this->dimension($request, $link);
            abort_if($dimension === null, 404);
            $data = $this->dimensionReport($reports, $site, $period, $dimension);
        } else {
            abort_unless($link->allows('overview'), 404);
            $data = $report === 'overview' ? $this->overview($reports, $site, $period) : ['data' => $reports->series($site, $period)];
        }

        return response()->json($data)->header('Cache-Control', 'no-store, private');
    }

    /** @return array{0: ShareLink, 1: Website} */
    private function context(Request $request): array
    {
        return [$request->attributes->get('share_link'), $request->attributes->get('share_site')];
    }

    /** Only these query values are read; filters, values and any site identifier are dropped. */
    private function period(Request $request, Website $site, Reports $reports): array
    {
        return $reports->period(Request::create('/', 'GET', $request->only(['period', 'from', 'to', 'granularity'])), $site);
    }

    private function dimension(Request $request, ShareLink $link): ?string
    {
        $allowed = $link->dimensions();
        $dimension = $request->query('dimension');
        if ($dimension === null) {
            return $link->allows('overview') ? null : ($allowed[0] ?? null);
        }
        abort_unless(is_string($dimension) && in_array($dimension, $allowed, true), 404);

        return $dimension;
    }

    /** The totals and coverage a visitor needs, without instance-level details. */
    private function overview(Reports $reports, Website $site, array $period): array
    {
        $report = $reports->overview($site, $period);

        return [
            'period' => $report['period'],
            'totals' => $report['totals'],
            'coverage' => $report['coverage'],
            'availability' => $report['availability'],
            'freshness' => ['aggregated_at' => $report['freshness']['aggregated_at']],
        ];
    }

    private function dimensionReport(Reports $reports, Website $site, array $period, string $dimension): array
    {
        $report = $reports->dimensionReport($site, $period, $dimension);

        return ['total' => $report['total'], 'distinct_values' => $report['distinct_values'], 'series' => $report['series'], 'data' => $report['data'], 'map' => $report['map']];
    }
}
