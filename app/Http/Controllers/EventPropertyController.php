<?php

namespace App\Http\Controllers;

use App\Analytics\Reporting\EventPropertyReports;
use App\Analytics\Reporting\Reports;
use App\Models\Website;
use App\Support\CsvSafe;
use App\Support\Ui;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The "event > property" report of a site (ADR 008). Members only; it is not one of the shareable sections.
 */
class EventPropertyController extends Controller
{
    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function load(Request $request, Website $website, Reports $reports, EventPropertyReports $properties): array
    {
        Gate::authorize('view', $website);
        abort_if($website->deleting_at, 404);
        $request->validate(['event' => ['sometimes', 'nullable', 'string', 'max:64', 'regex:/^[a-zA-Z][a-zA-Z0-9_.:-]*$|^\(other\)$/D']]);
        $period = $reports->period($request, $website);

        return [$period, $properties->report($website, $period, $request->input('event') ?: null)];
    }

    public function index(Request $request, Website $website, Reports $reports, EventPropertyReports $properties): Response
    {
        [$period, $report] = $this->load($request, $website, $reports, $properties);
        $query = $request->only(['period', 'from', 'to', 'event']);

        return Ui::render('event.properties', ['website' => $website, 'period' => $period, 'report' => $report, 'exportUrl' => route('sites.events.properties.export', ['website' => $website] + $query), 'apiUrl' => route('sites.events.properties.api', ['website' => $website] + $query)]);
    }

    public function api(Request $request, Website $website, Reports $reports, EventPropertyReports $properties): JsonResponse
    {
        [$period, $report] = $this->load($request, $website, $reports, $properties);

        return response()->json(['period' => ['from' => $period['from'], 'to' => $period['to']]] + $report)->header('Cache-Control', 'no-store, private');
    }

    public function export(Request $request, Website $website, Reports $reports, EventPropertyReports $properties): StreamedResponse
    {
        [, $report] = $this->load($request, $website, $reports, $properties);

        return response()->streamDownload(function () use ($report, $website): void {
            $stream = fopen('php://output', 'w');
            if ($website->is_demo) {
                fputcsv($stream, ['# demonstration data: synthetic events'], escape: '');
            }
            fputcsv($stream, ['event', 'property', 'value', 'count', 'value_total', 'value_average'], escape: '');
            foreach ($report['keys'] as $key) {
                foreach ($key['values'] as $row) {
                    fputcsv($stream, CsvSafe::row([$report['event'], $key['key'], $row['value'], $row['count'], $row['amount'], $row['average']]), escape: '');
                }
            }
            fclose($stream);
        }, 'perysee-event-properties.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }
}
