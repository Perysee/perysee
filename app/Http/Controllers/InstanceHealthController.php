<?php

namespace App\Http\Controllers;

use App\Analytics\Continuity\ContinuityLog;
use App\Analytics\Ingestion\Geolocation;
use App\Analytics\InstanceHealth;
use App\Modules\ModuleExtensions;
use App\Modules\ModuleFailures;
use App\Support\CliPhp;
use App\Support\Ui;
use App\Updates\UpdateStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    public function update(Request $request, InstanceHealth $health, ContinuityLog $continuity): RedirectResponse
    {
        abort_unless($request->user()->is_admin, 403);
        $data = $request->validate(['raw_retention_days' => ['required', 'integer', 'between:1,3650'], 'stats_retention_days' => ['required', 'integer', 'between:1,3650']]);
        DB::transaction(function () use ($data, $health, $continuity, $request): void {
            foreach ($data as $name => $days) {
                $before = $health->retentionDays($name);
                $health->put($name, (int) $days);
                if ($before !== (int) $days) {
                    // Keeping less history removes data for good, so figures before and after are no longer comparable.
                    $continuity->record('retention_change', null, (int) $days < $before ? ContinuityLog::BREAKS : ContinuityLog::NOTE, 'all', ['setting' => $name, 'from' => $before, 'to' => (int) $days], now('UTC')->toDateString(), $request->user()->id);
                }
            }
        });

        return back()->with('status', 'Retention updated. Expired data will be removed by scheduled maintenance.');
    }
}
