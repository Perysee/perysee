<?php

namespace App\Http\Controllers;

use App\Analytics\Reporting\Filters;
use App\Analytics\Reporting\Reports;
use App\Analytics\Reporting\SegmentReports;
use App\Models\Website;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AnalyticsController extends Controller
{
    private function authorizeSite(Website $site): void
    {
        Gate::authorize('view', $site);
        abort_if($site->deleting_at, 404);
    }

    public function api(Request $request, Website $website, string $report, Reports $reports): JsonResponse
    {
        $this->authorizeSite($website);
        $period = $reports->period($request, $website);
        $data = match ($report) {
            'overview' => $reports->overview($website, $period),
            'timeseries' => ['data' => $reports->series($website, $period)],
            'dimensions' => $reports->dimensionReport($website, $period, $request->input('dimension', 'path'), $request->input('value')),
            'recent' => ['data' => $reports->recent($website), 'totals' => $reports->receptionTotals($website), 'checked_at' => now('UTC')->toIso8601String()],
        };

        return response()->json($data)->header('Cache-Control', 'no-store, private');
    }

    public function filterOptions(Request $request, Website $website, Reports $reports): JsonResponse
    {
        $this->authorizeSite($website);
        $data = $request->validate(['filter_dimension' => ['required', 'in:'.implode(',', Filters::DIMENSIONS)], 'q' => ['nullable', 'string', 'max:150']]);

        return response()->json(['data' => app(SegmentReports::class)->options($website, $reports->period($request, $website), $data['filter_dimension'], $data['q'] ?? '')])->header('Cache-Control', 'no-store, private');
    }

    public function export(Request $request, Website $website, Reports $reports): StreamedResponse
    {
        $this->authorizeSite($website);
        $period = $reports->period($request, $website);
        $dimension = $request->input('dimension');

        return response()->streamDownload(function () use ($reports, $website, $period, $dimension, $request): void {
            $stream = fopen('php://output', 'w');
            if ($website->is_demo) {
                fputcsv($stream, ['# demonstration data: synthetic events'], escape: '');
            }
            if ($dimension) {
                fputcsv($stream, ['value', 'count'], escape: '');
                foreach ($reports->dimensions($website, $period, $dimension, $request->input('value')) as $row) {
                    $value = preg_match('/^[\s]*[=+@-]/u', $row['value']) ? "'".$row['value'] : $row['value'];
                    fputcsv($stream, [$value, $row['count']], escape: '');
                }
            } else {
                fputcsv($stream, ['date', 'pageviews', 'custom_events', 'sessions_started', 'session_availability'], escape: '');
                $daily = array_merge($period, ['granularity' => 'day']);
                foreach ($reports->series($website, $daily) as $row) {
                    fputcsv($stream, [$row['date'], $row['pageviews'], $row['custom_events'], $row['sessions'], $row['session_availability']], escape: '');
                }
            }
            fclose($stream);
        }, 'perysee-'.$website->id.'-'.$period['from'].'-'.$period['to'].'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }
}
