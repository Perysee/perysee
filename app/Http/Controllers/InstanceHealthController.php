<?php

namespace App\Http\Controllers;

use App\Analytics\Continuity\ContinuityLog;
use App\Analytics\Ingestion\Geolocation;
use App\Analytics\InstanceHealth;
use App\Analytics\StorageBudget;
use App\Analytics\StorageTiers;
use App\Modules\Events\DomainEvents;
use App\Modules\ModuleExtensions;
use App\Modules\ModuleFailures;
use App\Support\AccessLog;
use App\Support\CliPhp;
use App\Support\Ui;
use App\Updates\UpdateStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Response;

class InstanceHealthController extends Controller
{
    public function show(Request $request, InstanceHealth $health): Response
    {
        abort_unless($request->user()->is_admin, 403);

        $snapshot = $health->snapshot();

        return Ui::render('health', ['health' => $snapshot, 'geolocation' => $snapshot['geolocation'], 'moduleCards' => app(ModuleExtensions::class)->healthCards(), 'moduleFailures' => app(ModuleFailures::class)->summary(), 'softwareUpdate' => app(UpdateStatus::class)->snapshot(), 'cron' => app(CliPhp::class)->cronLine(base_path())]);
    }

    public function api(Request $request, InstanceHealth $health): JsonResponse
    {
        abort_unless($request->user()->is_admin, 403);

        return response()->json($health->snapshot())->header('Cache-Control', 'no-store, private');
    }

    public function importGeolocation(Request $request): RedirectResponse
    {
        abort_unless($request->user()->is_admin, 403);
        $request->validate(['database' => ['required', 'file', 'max:20480']]);
        app(Geolocation::class)->import($request->file('database'));

        return back()->with('status', 'Country database updated. Historical events are unchanged.');
    }

    public function storage(Request $request, InstanceHealth $health): RedirectResponse
    {
        abort_unless($request->user()->is_admin, 403);
        // Up to 16 TiB, entered in MiB like the figures of the page; empty removes the quota.
        $mebibytes = $request->validate(['storage_quota_mib' => ['nullable', 'integer', 'between:1,16777216']])['storage_quota_mib'] ?? null;
        if ($mebibytes === null) {
            $health->forget('storage_quota_bytes');

            return back()->with('status', 'Storage quota removed.');
        }
        $health->put('storage_quota_bytes', (int) $mebibytes * 1048576);

        return back()->with('status', 'Storage quota saved.');
    }

    /** The storage budget and its mode (ADR 011). Choosing `auto` is an explicit decision, kept in the access log. */
    public function budget(Request $request, InstanceHealth $health, StorageBudget $budget): RedirectResponse
    {
        abort_unless($request->user()->is_admin, 403);
        $data = $request->validate([
            'storage_budget_mib' => ['nullable', 'integer', 'between:1,16777216'],
            'storage_mode' => ['required', Rule::in(StorageBudget::MODES)],
        ]);
        $mebibytes = $data['storage_budget_mib'] ?? null;
        if ($mebibytes === null && $data['storage_mode'] !== 'off') {
            throw ValidationException::withMessages(['storage_mode' => 'Enter a budget to use the ask or auto mode.']);
        }
        $before = $budget->settings();
        $bytes = $mebibytes === null ? null : (int) $mebibytes * 1048576;
        $bytes === null ? $health->forget(StorageBudget::BUDGET) : $health->put(StorageBudget::BUDGET, $bytes);
        $health->put(StorageBudget::MODE, $data['storage_mode']);
        if ($before['budget_bytes'] !== $bytes || $before['mode'] !== $data['storage_mode']) {
            AccessLog::record(AccessLog::STORAGE_SETTINGS, $request->user(), $request, ['mode' => $data['storage_mode'], 'budget_mib' => $mebibytes === null ? null : (int) $mebibytes]);
        }

        return back()->with('status', 'Storage budget saved. It is checked every hour.');
    }

    /** The administrator allows the step the page proposed in `ask` mode. */
    public function agree(Request $request, StorageBudget $budget): RedirectResponse
    {
        abort_unless($request->user()->is_admin, 403);
        $tier = (int) $request->validate(['tier' => ['required', 'integer', 'between:1,'.StorageTiers::MAX]])['tier'];
        if (! $budget->agree($tier)) {
            throw ValidationException::withMessages(['tier' => 'This reduction is not proposed any more. Reload the page.']);
        }
        AccessLog::record(AccessLog::STORAGE_AGREED, $request->user(), $request, ['tier' => $tier]);

        return back()->with('status', 'Agreed. The retention is reduced at the next hourly check.');
    }

    public function update(Request $request, InstanceHealth $health, ContinuityLog $continuity): RedirectResponse
    {
        abort_unless($request->user()->is_admin, 403);
        $data = $request->validate(['raw_retention_days' => ['required', 'integer', 'between:1,3650'], 'stats_retention_days' => ['required', 'integer', 'between:1,3650']]);
        $events = DB::transaction(function () use ($data, $health, $continuity, $request): array {
            $events = [];
            foreach ($data as $name => $days) {
                $before = $health->retentionDays($name);
                $health->put($name, (int) $days);
                if ($before !== (int) $days) {
                    // Keeping less history removes data for good, so figures before and after are no longer comparable.
                    $events[] = $continuity->record('retention_change', null, (int) $days < $before ? ContinuityLog::BREAKS : ContinuityLog::NOTE, 'all', ['setting' => $name, 'from' => $before, 'to' => (int) $days], now('UTC')->toDateString(), $request->user()->id);
                }
            }

            return array_values(array_filter($events));
        });
        foreach ($events as $event) {
            app(DomainEvents::class)->publishAfterResponse($event);
        }

        return back()->with('status', 'Retention updated. Expired data will be removed by scheduled maintenance.');
    }
}
