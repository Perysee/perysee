<?php

namespace App\Http\Controllers\Api\V1;

use App\Analytics\Reporting\ReportPresenter;
use App\Analytics\Reporting\ReportSections;
use App\Analytics\Reporting\Reports;
use App\Http\Api\ApiError;
use App\Http\Api\ApiScopes;
use App\Http\Controllers\Controller;
use App\Models\Website;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The overview, the time series and the dimension reports. Every field comes from an allow-list in ReportPresenter. */
class ReportController extends Controller
{
    private const DIMENSIONS = ['path', 'source', 'campaign', 'device', 'device_brand', 'browser', 'country', 'event'];

    public function __construct(private Reports $reports, private ReportPresenter $presenter) {}

    private function site(Request $request): Website
    {
        return $request->attributes->get('api_site');
    }

    /** The period of the request, after the scope check: a request a token may not make is refused before any query. */
    private function period(Request $request, string $scope): array
    {
        ApiScopes::require($request, $scope);
        $period = $this->reports->period($request, $this->site($request));
        ApiScopes::requireForPeriod($request, $period);

        return $period;
    }

    public function overview(Request $request): JsonResponse
    {
        $site = $this->site($request);
        $period = $this->period($request, 'overview');
        $overview = $this->reports->overview($site, $period);
        $data = $this->presenter->apiOverviewOf($overview);
        if ($period['compare'] === 'previous') {
            $data['comparison'] = $this->presenter->apiComparisonOf($this->reports->comparison($site, $period, $overview));
        }

        return response()->json(['data' => $data, 'meta' => ['api_version' => 1]]);
    }

    public function timeseries(Request $request): JsonResponse
    {
        $site = $this->site($request);
        $period = $this->period($request, 'overview');

        return response()->json(['data' => [
            'period' => ['from' => $period['from'], 'to' => $period['to'], 'timezone' => $site->timezone],
            'granularity' => $period['granularity'],
            'points' => $this->presenter->apiPoints($this->reports->series($site, $period)),
        ], 'meta' => ['api_version' => 1]]);
    }

    public function dimension(Request $request, string $dimension): JsonResponse
    {
        if (! in_array($dimension, self::DIMENSIONS, true)) {
            throw ApiError::notFound();
        }
        $site = $this->site($request);
        $period = $this->period($request, ReportSections::sectionOf($dimension));
        $value = $request->query('value');
        $report = $this->presenter->dimensionReport($site, $period, $dimension, is_string($value) ? $value : null);

        return response()->json(['data' => [
            'dimension' => $dimension,
            'period' => ['from' => $period['from'], 'to' => $period['to'], 'timezone' => $site->timezone],
            'total' => $report['total'],
            'distinct_values' => $report['distinct_values'],
            'series' => array_map(fn (array $point): array => ['date' => $point['date'], 'pageviews' => $point['pageviews'], 'custom_events' => $point['custom_events']], $report['series']),
            'rows' => $report['data'],
        ], 'meta' => ['api_version' => 1, 'limit' => 100, 'truncated' => $report['distinct_values'] > count($report['data'])]]);
    }
}
