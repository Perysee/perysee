<?php

namespace App\Http\Controllers\Api\V1;

use App\Analytics\Reporting\GoalReports;
use App\Analytics\Reporting\Reports;
use App\Http\Api\ApiError;
use App\Http\Api\ApiScopes;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Goal definitions and their reports, the same figures as the goal screens and their JSON. */
class GoalController extends Controller
{
    /** The scope each conversion breakdown depends on. */
    private const BREAKDOWN_SCOPES = ['source' => 'sources', 'campaign' => 'campaigns', 'country' => 'countries', 'device' => 'devices'];

    public function __construct(private GoalReports $goals, private Reports $reports) {}

    private function describe(object $goal, object $version): array
    {
        return ['id' => (int) $goal->id, 'name' => $goal->name, 'archived' => $goal->archived_at !== null, 'version' => (int) $version->version, 'type' => $version->type, 'value' => $version->value, 'threshold' => (int) $version->threshold];
    }

    public function index(Request $request): JsonResponse
    {
        ApiScopes::require($request, 'goals');
        $site = $request->attributes->get('api_site');
        $versions = DB::table('goal_versions')->where('website_id', $site->id)->get()->keyBy(fn ($row) => $row->goal_id.'|'.$row->version);
        $data = DB::table('goals')->where('website_id', $site->id)->orderBy('name')->orderBy('id')->get()
            ->filter(fn ($goal) => $versions->has($goal->id.'|'.$goal->current_version))
            ->map(fn ($goal): array => $this->describe($goal, $versions->get($goal->id.'|'.$goal->current_version)))->values()->all();

        return response()->json(['data' => $data, 'meta' => ['api_version' => 1]]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        ApiScopes::require($request, 'goals');
        $site = $request->attributes->get('api_site');
        $definition = ctype_digit($id) ? $this->goals->definition($site, (int) $id) : null;
        if ($definition === null) {
            throw ApiError::notFound();
        }
        $request->validate(['version' => ['sometimes', 'integer', 'min:1']]);
        $version = $this->goals->version($definition, $request->has('version') ? $request->integer('version') : null) ?? throw ApiError::notFound();
        $period = $this->reports->period($request, $site);
        ApiScopes::requireForPeriod($request, $period);
        $report = $this->goals->report($site, (int) $definition->id, (int) $version->version, $period);

        $report = array_intersect_key($report, array_flip(['mode', 'totals', 'series', 'breakdown', 'coverage']));
        // A breakdown is a report by that dimension: the token needs the scope that opens it (a goals-only token gets none).
        $report['breakdown'] = array_filter($report['breakdown'] ?? [], fn (string $dimension): bool => ApiScopes::token($request)->allows(self::BREAKDOWN_SCOPES[$dimension] ?? '-'), ARRAY_FILTER_USE_KEY);

        return response()->json(['data' => [
            'goal' => $this->describe($definition, $version),
            'period' => ['from' => $period['from'], 'to' => $period['to']],
            'report' => $report,
        ], 'meta' => ['api_version' => 1]]);
    }
}
