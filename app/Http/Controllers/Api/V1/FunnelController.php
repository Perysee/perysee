<?php

namespace App\Http\Controllers\Api\V1;

use App\Analytics\Reporting\JourneyReports;
use App\Analytics\Reporting\Reports;
use App\Http\Api\ApiError;
use App\Http\Api\ApiScopes;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Funnel definitions and the results already calculated. Reading never starts a calculation: it is asynchronous
 * and writes rows, which a token must not be able to trigger.
 */
class FunnelController extends Controller
{
    public function __construct(private JourneyReports $journeys, private Reports $reports) {}

    /** @return list<array{type: string, value: string, label: string|null}> */
    private function steps(?object $version): array
    {
        $steps = $version ? json_decode($version->steps, true, flags: JSON_THROW_ON_ERROR) : [];

        return array_map(fn (array $step): array => ['type' => $step['type'], 'value' => $step['value'], 'label' => $step['label'] ?? null], $steps);
    }

    private function describe(object $funnel, ?object $version): array
    {
        return ['id' => (int) $funnel->id, 'name' => $funnel->name, 'archived' => $funnel->archived_at !== null, 'version' => (int) ($version->version ?? $funnel->current_version), 'steps' => $this->steps($version)];
    }

    public function index(Request $request): JsonResponse
    {
        ApiScopes::require($request, 'funnels');
        $site = $request->attributes->get('api_site');
        $versions = DB::table('funnel_versions')->where('website_id', $site->id)->get()->keyBy(fn ($row) => $row->funnel_id.'|'.$row->version);
        $data = DB::table('funnels')->where('website_id', $site->id)->orderBy('name')->orderBy('id')->get()
            ->map(fn ($funnel): array => $this->describe($funnel, $versions->get($funnel->id.'|'.$funnel->current_version)))->all();

        return response()->json(['data' => $data, 'meta' => ['api_version' => 1]]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        ApiScopes::require($request, 'funnels');
        $site = $request->attributes->get('api_site');
        $funnel = ctype_digit($id) ? DB::table('funnels')->where('website_id', $site->id)->where('id', (int) $id)->first() : null;
        if ($funnel === null) {
            throw ApiError::notFound();
        }
        $request->validate(['version' => ['sometimes', 'integer', 'min:1']]);
        $version = DB::table('funnel_versions')->where('funnel_id', $funnel->id)->where('version', $request->has('version') ? $request->integer('version') : $funnel->current_version)->first() ?? throw ApiError::notFound();
        $period = $this->reports->period($request, $site);
        ApiScopes::requireForPeriod($request, $period);
        $report = $this->journeys->latestReady($site, (int) $funnel->id, (int) $version->version, $period);
        if ($report === null) {
            throw new ApiError(404, 'report_not_computed', 'This funnel has not been calculated for this period and these filters. Open it once in the interface to calculate it.');
        }
        $result = json_decode($report->result, true, flags: JSON_THROW_ON_ERROR);

        return response()->json(['data' => [
            'funnel' => $this->describe($funnel, $version),
            'period' => ['from' => $period['from'], 'to' => $period['to']],
            'result' => [
                'entries' => $result['entries'], 'conversions' => $result['conversions'], 'pending' => $result['pending'], 'conversion_rate' => $result['conversion_rate'],
                'steps' => array_map(fn (array $step): array => ['label' => $step['label'], 'count' => $step['count'], 'rate' => $step['rate'], 'abandoned' => $step['abandoned'], 'pending' => $step['pending']], $result['steps']),
                'coverage' => isset($result['coverage']) ? array_intersect_key($result['coverage'], array_flip(['status', 'excluded_sessions', 'raw_retention_days', 'message'])) : null,
            ],
            'computed_at' => Carbon::parse($report->updated_at, 'UTC')->toIso8601String(),
        ], 'meta' => ['api_version' => 1]]);
    }
}
