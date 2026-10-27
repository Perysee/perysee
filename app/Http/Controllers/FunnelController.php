<?php

namespace App\Http\Controllers;

use App\Analytics\Privacy\NormalizePath;
use App\Analytics\Reporting\JourneyReports;
use App\Analytics\Reporting\Reports;
use App\Models\Website;
use App\Support\Ui;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FunnelController extends Controller
{
    private function authorizeSite(Website $site, bool $write = false): void
    {
        Gate::authorize($write ? 'update' : 'view', $site);
        abort_if($site->deleting_at, 404);
    }

    private function definition(Website $site, int $id): object
    {
        return DB::table('funnels')->where('website_id', $site->id)->where('id', $id)->firstOrFail();
    }

    private function data(Request $request): array
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'steps' => ['required', 'array', 'min:2', 'max:8'], 'steps.*' => ['array:type,value,label'], 'steps.*.type' => ['required', 'in:path,event'], 'steps.*.value' => ['required', 'string', 'max:512'], 'steps.*.label' => ['nullable', 'string', 'max:100']]);
        foreach ($data['steps'] as $index => &$step) {
            if ($step['type'] === 'path') {
                $step['value'] = app(NormalizePath::class)($step['value']);
            } else {
                if (! preg_match('/^[a-zA-Z][a-zA-Z0-9_.:-]{0,63}$/', $step['value'])) {
                    throw ValidationException::withMessages(['steps.'.$index.'.value' => 'Use a named event of at most 64 letters, digits, dots, underscores, colons or hyphens.']);
                }
            }
            if (empty($step['label'])) {
                unset($step['label']);
            }
        }

        return $data;
    }

    public function index(Request $request, Website $website, Reports $reports): Response
    {
        $this->authorizeSite($website);
        $funnels = DB::table('funnels')->where('website_id', $website->id)->orderBy('name')->get()->map(fn ($funnel) => (array) $funnel + ['url' => route('sites.funnels.show', [$website, $funnel->id]), 'update_url' => route('sites.funnels.update', [$website, $funnel->id]), 'archive_url' => route('sites.funnels.archive', [$website, $funnel->id])]);

        return Ui::render('funnels', ['website' => $website, 'funnels' => $funnels, 'period' => $reports->period($request, $website), 'createUrl' => route('sites.funnels.store', $website)]);
    }

    public function store(Request $request, Website $website): RedirectResponse
    {
        $this->authorizeSite($website, true);
        $data = $this->data($request);
        $id = DB::transaction(function () use ($website, $data): int {
            $id = DB::table('funnels')->insertGetId(['website_id' => $website->id, 'name' => $data['name'], 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
            DB::table('funnel_versions')->insert(['website_id' => $website->id, 'funnel_id' => $id, 'version' => 1, 'steps' => json_encode($data['steps'], JSON_THROW_ON_ERROR), 'created_at' => now('UTC')]);

            return $id;
        });

        return redirect()->route('sites.funnels.show', [$website, $id]);
    }

    public function update(Request $request, Website $website, int $funnel): RedirectResponse
    {
        $this->authorizeSite($website, true);
        $this->definition($website, $funnel);
        $data = $this->data($request);
        DB::transaction(function () use ($website, $funnel, $data): void {
            $current = DB::table('funnels')->where('id', $funnel)->lockForUpdate()->firstOrFail();
            abort_if($current->archived_at, 409);
            $version = $current->current_version + 1;
            DB::table('funnel_versions')->insert(['website_id' => $website->id, 'funnel_id' => $funnel, 'version' => $version, 'steps' => json_encode($data['steps'], JSON_THROW_ON_ERROR), 'created_at' => now('UTC')]);
            DB::table('funnels')->where('id', $funnel)->update(['name' => $data['name'], 'current_version' => $version, 'updated_at' => now('UTC')]);
        });

        return redirect()->route('sites.funnels.show', [$website, $funnel])->with('status', 'New funnel version saved. Earlier definitions remain available.');
    }

    public function archive(Website $website, int $funnel): RedirectResponse
    {
        $this->authorizeSite($website, true);
        $this->definition($website, $funnel);
        DB::table('funnels')->where('id', $funnel)->update(['archived_at' => now('UTC'), 'updated_at' => now('UTC')]);

        return redirect()->route('sites.funnels.index', $website)->with('status', 'Funnel archived.');
    }

    public function show(Request $request, Website $website, int $funnel, Reports $reports, JourneyReports $jobs): Response
    {
        $this->authorizeSite($website);
        $definition = $this->definition($website, $funnel);
        $request->validate(['version' => ['sometimes', 'integer', 'min:1']]);
        $version = DB::table('funnel_versions')->where('funnel_id', $funnel)->where('version', $request->integer('version', $definition->current_version))->firstOrFail();
        $period = $reports->period($request, $website);
        $steps = json_decode($version->steps, true, flags: JSON_THROW_ON_ERROR);
        $job = $website->tracking_mode === 'sessions' ? $jobs->request($website, ['kind' => 'funnel', 'funnel_id' => $funnel, 'version' => $version->version, 'steps' => $steps, ...$this->payload($period)]) : null;

        return Ui::render('funnel.report', ['website' => $website, 'period' => $period, 'funnel' => (array) $definition, 'steps' => $steps, 'versions' => DB::table('funnel_versions')->where('funnel_id', $funnel)->orderByDesc('version')->pluck('version'), 'version' => $version->version, 'job' => $job ? $jobs->publicStatus($job) : null, 'reportLinks' => $this->links($website, $job), 'updateUrl' => route('sites.funnels.update', [$website, $funnel]), 'archiveUrl' => route('sites.funnels.archive', [$website, $funnel]), 'calculateUrl' => route('sites.funnels.calculate', [$website, $funnel])]);
    }

    public function journeys(Request $request, Website $website, Reports $reports, JourneyReports $jobs): Response
    {
        $this->authorizeSite($website);
        $data = $request->validate(['start_type' => ['sometimes', 'in:path,event'], 'start_value' => ['sometimes', 'string', 'max:512']]);
        $start = ['type' => $data['start_type'] ?? 'path', 'value' => $data['start_value'] ?? '/'];
        if ($start['type'] === 'path') {
            $start['value'] = app(NormalizePath::class)($start['value']);
        } else {
            validator($start, ['value' => ['regex:/^[a-zA-Z][a-zA-Z0-9_.:-]{0,63}$/']])->validate();
        }
        $period = $reports->period($request, $website);
        $job = $website->tracking_mode === 'sessions' ? $jobs->request($website, ['kind' => 'journey', 'start' => $start, ...$this->payload($period)]) : null;

        return Ui::render('journeys', ['website' => $website, 'period' => $period, 'start' => $start, 'calculateUrl' => route('sites.journeys.calculate', $website), 'job' => $job ? $jobs->publicStatus($job) : null, 'reportLinks' => $this->links($website, $job)]);
    }

    private function payload(array $period): array
    {
        return ['from' => $period['start']->toDateTimeString(), 'to' => $period['end']->toDateTimeString(), 'filters' => $period['filters']];
    }

    private function links(Website $site, ?object $job): array
    {
        return $job ? ['request' => route('sites.journeys.calculate', $site), 'status' => route('sites.journeys.status', [$site, $job->id]), 'export' => route('sites.journeys.export', [$site, $job->id])] : [];
    }

    public function calculate(Request $request, Website $website, ?int $funnel = null): RedirectResponse
    {
        $this->authorizeSite($website);
        if ($funnel !== null) {
            $this->definition($website, $funnel);
        }
        $parameters = $request->only(['from', 'to', 'period', 'filters', 'version', 'start_type', 'start_value']);

        return redirect()->route($funnel === null ? 'sites.journeys' : 'sites.funnels.show', ['website' => $website, ...($funnel !== null ? ['funnel' => $funnel] : []), ...$parameters], 303);
    }

    public function status(Website $website, int $report, JourneyReports $jobs): JsonResponse
    {
        $this->authorizeSite($website);

        return response()->json($jobs->publicStatus(DB::table('journey_reports')->where('website_id', $website->id)->where('id', $report)->firstOrFail()))->header('Cache-Control', 'no-store, private');
    }

    public function export(Website $website, int $report): StreamedResponse
    {
        $this->authorizeSite($website);
        $job = DB::table('journey_reports')->where('website_id', $website->id)->where('id', $report)->firstOrFail();
        abort_unless($job->status === 'ready', 409);
        $result = json_decode($job->result, true, flags: JSON_THROW_ON_ERROR);

        return response()->streamDownload(function () use ($result, $website): void {
            $stream = fopen('php://output', 'w');
            if ($website->is_demo) {
                fputcsv($stream, ['# demonstration data: synthetic events'], escape: '');
            }
            $safe = fn ($value) => preg_match('/^[\s]*[=+@-]/u', (string) $value) ? "'".$value : $value;
            if ($result['steps']) {
                fputcsv($stream, ['step', 'sessions', 'step_rate_percent', 'abandoned', 'pending'], escape: '');
                foreach ($result['steps'] as $row) {
                    fputcsv($stream, array_map($safe, [$row['label'], $row['count'], $row['rate'], $row['abandoned'], $row['pending']]), escape: '');
                }
            } else {
                $labels = array_column($result['nodes'], 'label', 'name');
                fputcsv($stream, ['source_id', 'source', 'target_id', 'target', 'sessions'], escape: '');
                foreach ($result['links'] as $link) {
                    fputcsv($stream, array_map($safe, [$link['source'], $labels[$link['source']], $link['target'], $labels[$link['target']], $link['value']]), escape: '');
                }
            }
            fclose($stream);
        }, 'perysee-journey-'.$report.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }
}
