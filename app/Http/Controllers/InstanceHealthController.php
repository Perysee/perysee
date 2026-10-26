<?php

namespace App\Http\Controllers;

use App\Analytics\Ingestion\Geolocation;
use App\Analytics\InstanceHealth;
use App\Support\Ui;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

class InstanceHealthController extends Controller
{
    public function show(Request $request, InstanceHealth $health): Response
    {
        abort_unless($request->user()->is_admin, 403);

        return Ui::render('health', ['health' => $health->snapshot(), 'geolocation' => app(Geolocation::class)->status(), 'cron' => '* * * * * cd '.escapeshellarg(base_path()).' && '.escapeshellarg(PHP_BINARY).' artisan schedule:run >> /dev/null 2>&1']);
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

    public function update(Request $request, InstanceHealth $health): RedirectResponse
    {
        abort_unless($request->user()->is_admin, 403);
        $data = $request->validate(['raw_retention_days' => ['required', 'integer', 'between:1,3650'], 'stats_retention_days' => ['required', 'integer', 'between:1,3650']]);
        foreach ($data as $name => $days) {
            $health->put($name, (int) $days);
        }

        return back()->with('status', 'Retention updated. Expired data will be removed by scheduled maintenance.');
    }
}
