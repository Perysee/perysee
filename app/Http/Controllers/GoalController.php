<?php

namespace App\Http\Controllers;

use App\Analytics\Aggregation\Goals;
use App\Analytics\Privacy\NormalizePath;
use App\Analytics\Reporting\GoalReports;
use App\Analytics\Reporting\Reports;
use App\Models\Website;
use App\Support\CsvSafe;
use App\Support\Ui;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GoalController extends Controller
{
    public const MAX_ACTIVE = 5;

    private function authorizeSite(Website $site, bool $write = false): void
    {
        Gate::authorize($write ? 'update' : 'view', $site);
        abort_if($site->deleting_at, 404);
    }

    private function definition(Website $site, int $id): object
    {
        return app(GoalReports::class)->definition($site, $id) ?? abort(404);
    }

    private function data(Request $request): array
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'type' => ['required', 'in:event,path'], 'value' => ['required', 'string', 'max:512'], 'threshold' => ['sometimes', 'integer', 'min:1', 'max:1000']]);
        if ($data['type'] === 'path') {
            $data['value'] = app(NormalizePath::class)($data['value']);
        } elseif (! preg_match('/^[a-zA-Z][a-zA-Z0-9_.:-]{0,63}$/', $data['value'])) {
            throw ValidationException::withMessages(['value' => 'Use a named event of at most 64 letters, digits, dots, underscores, colons or hyphens.']);
        }
        $data['threshold'] ??= 1;

        return $data;
    }

    private function version(Website $site, int $goal, int $version, array $data): void
    {
        $snapshot = (int) DB::table('events')->where('website_id', $site->id)->max('id');
        DB::table('goal_versions')->insert(['website_id' => $site->id, 'goal_id' => $goal, 'version' => $version, 'type' => $data['type'], 'value' => $data['value'], 'threshold' => $data['threshold'], 'snapshot_id' => $snapshot, 'created_at' => now('UTC')]);
        // Events that already exist are counted by the scheduler; later ones by the aggregator.
        app(Goals::class)->queueBackfill($site->id, $goal, $version, $snapshot);
    }

    public function index(Request $request, Website $website, Reports $reports): Response
    {
        $this->authorizeSite($website);
        $versions = DB::table('goal_versions')->where('website_id', $website->id)->get()->keyBy(fn ($row) => $row->goal_id.'|'.$row->version);
        $goals = DB::table('goals')->where('website_id', $website->id)->orderBy('name')->get()->map(function ($goal) use ($website, $versions): array {
            $current = $versions->get($goal->id.'|'.$goal->current_version);

            return (array) $goal + ['type' => $current->type, 'value' => $current->value, 'threshold' => (int) $current->threshold, 'url' => route('sites.goals.show', [$website, $goal->id]), 'update_url' => route('sites.goals.update', [$website, $goal->id]), 'archive_url' => route('sites.goals.archive', [$website, $goal->id])];
        });

        return Ui::render('goals', ['website' => $website, 'goals' => $goals, 'limit' => self::MAX_ACTIVE, 'period' => $reports->period($request, $website), 'createUrl' => route('sites.goals.store', $website)]);
    }

    public function store(Request $request, Website $website): RedirectResponse
    {
        $this->authorizeSite($website, true);
        $data = $this->data($request);
        $id = DB::transaction(function () use ($website, $data): int {
            // Serialises concurrent creations on the site row so the limit cannot be exceeded.
            DB::table('websites')->where('id', $website->id)->lockForUpdate()->first();
            if (DB::table('goals')->where('website_id', $website->id)->whereNull('archived_at')->count() >= self::MAX_ACTIVE) {
                throw ValidationException::withMessages(['name' => 'A site can have at most '.self::MAX_ACTIVE.' active goals. Archive one first.']);
            }
            $id = DB::table('goals')->insertGetId(['website_id' => $website->id, 'name' => $data['name'], 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
            $this->version($website, $id, 1, $data);

            return $id;
        });

        return redirect()->route('sites.goals.show', [$website, $id]);
    }

    public function update(Request $request, Website $website, int $goal): RedirectResponse
    {
        $this->authorizeSite($website, true);
        $this->definition($website, $goal);
        $data = $this->data($request);
        DB::transaction(function () use ($website, $goal, $data): void {
            $current = DB::table('goals')->where('id', $goal)->lockForUpdate()->firstOrFail();
            abort_if($current->archived_at, 409);
            $version = $current->current_version + 1;
            $this->version($website, $goal, $version, $data);
            DB::table('goals')->where('id', $goal)->update(['name' => $data['name'], 'current_version' => $version, 'updated_at' => now('UTC')]);
        });

        return redirect()->route('sites.goals.show', [$website, $goal])->with('status', 'New goal version saved. Earlier definitions remain available.');
    }

    public function archive(Website $website, int $goal): RedirectResponse
    {
        $this->authorizeSite($website, true);
        $this->definition($website, $goal);
        DB::table('goals')->where('id', $goal)->update(['archived_at' => now('UTC'), 'updated_at' => now('UTC')]);

        return redirect()->route('sites.goals.index', $website)->with('status', 'Goal archived.');
    }

    /**
     * @return array{0: object, 1: object, 2: array<string, mixed>, 3: array<string, mixed>}
     */
    private function load(Request $request, Website $website, int $goal, Reports $reports, GoalReports $goals): array
    {
        $this->authorizeSite($website);
        $definition = $this->definition($website, $goal);
        $request->validate(['version' => ['sometimes', 'integer', 'min:1']]);
        $version = DB::table('goal_versions')->where('goal_id', $goal)->where('version', $request->integer('version', $definition->current_version))->firstOrFail();
        $period = $reports->period($request, $website);

        return [$definition, $version, $period, $goals->report($website, $goal, $version->version, $period)];
    }

    public function show(Request $request, Website $website, int $goal, Reports $reports, GoalReports $goals): Response
    {
        [$definition, $version, $period, $report] = $this->load($request, $website, $goal, $reports, $goals);
        $query = $request->only(['period', 'from', 'to', 'filters', 'version']);

        return Ui::render('goal.report', ['website' => $website, 'period' => $period, 'goal' => (array) $definition, 'definition' => (array) $version, 'report' => $report, 'versions' => DB::table('goal_versions')->where('goal_id', $goal)->orderByDesc('version')->pluck('version'), 'version' => $version->version, 'updateUrl' => route('sites.goals.update', [$website, $goal]), 'archiveUrl' => route('sites.goals.archive', [$website, $goal]), 'exportUrl' => route('sites.goals.export', ['website' => $website, 'goal' => $goal] + $query), 'apiUrl' => route('sites.goals.api', ['website' => $website, 'goal' => $goal] + $query)]);
    }

    public function api(Request $request, Website $website, int $goal, Reports $reports, GoalReports $goals): JsonResponse
    {
        [$definition, $version, $period, $report] = $this->load($request, $website, $goal, $reports, $goals);

        return response()->json(['goal' => ['id' => $definition->id, 'name' => $definition->name, 'archived' => $definition->archived_at !== null, 'version' => $version->version, 'type' => $version->type, 'value' => $version->value, 'threshold' => (int) $version->threshold], 'period' => ['from' => $period['from'], 'to' => $period['to']]] + $report)->header('Cache-Control', 'no-store, private');
    }

    public function export(Request $request, Website $website, int $goal, Reports $reports, GoalReports $goals): StreamedResponse
    {
        [, $version, , $report] = $this->load($request, $website, $goal, $reports, $goals);

        return response()->streamDownload(function () use ($report, $website): void {
            $stream = fopen('php://output', 'w');
            if ($website->is_demo) {
                fputcsv($stream, ['# demonstration data: synthetic events'], escape: '');
            }
            // The value columns come last and only when a hit of the period carried a value, so an export without values keeps its shape.
            $valued = $report['amounts']['valued_hits'] > 0;
            fputcsv($stream, array_merge(['dimension', 'value', 'conversions', 'sessions', 'rate_percent'], $valued ? ['value_total', 'value_average'] : []), escape: '');
            fputcsv($stream, CsvSafe::row(array_merge(['total', 'all', $report['totals']['conversions'], $report['totals']['sessions'], $report['totals']['rate']], $valued ? [$report['amounts']['total'], $report['amounts']['average']] : [])), escape: '');
            foreach ($report['breakdown'] as $dimension => $rows) {
                foreach ($rows as $row) {
                    fputcsv($stream, CsvSafe::row(array_merge([$dimension, $row['label'], $row['conversions'], $row['sessions'], $row['rate']], $valued ? [$row['amount'], $row['average']] : [])), escape: '');
                }
            }
            fclose($stream);
        }, 'perysee-goal-'.$goal.'-v'.$version->version.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }
}
