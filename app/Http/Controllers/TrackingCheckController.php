<?php

namespace App\Http\Controllers;

use App\Analytics\Diagnostics\TrackerCheck;
use App\Analytics\Ingestion\ProbeToken;
use App\Models\Website;
use App\Support\Ui;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Response;

/** The "Verify the installation" page of a site and the token behind its "Test now" button. */
class TrackingCheckController extends Controller
{
    public function show(Website $website, TrackerCheck $check): Response
    {
        Gate::authorize('view', $website);
        abort_if($website->deleting_at, 404);

        // The check is a lazy partial prop: a refresh asks for it alone and the page around it is not rebuilt.
        return Ui::render('sites.verify', ['website' => $website, 'check' => fn () => $check->for($website), 'collectorRoot' => rtrim(url('/'), '/')]);
    }

    /** A token the browser presents to the real collector to prove, without storing an event, that it works. Owners only: it writes. */
    public function probeToken(Website $website, ProbeToken $tokens): JsonResponse
    {
        Gate::authorize('update', $website);
        abort_if($website->deleting_at, 404);
        $issued = $tokens->issue($website->public_id);

        return response()->json(['token' => $issued['token'], 'expires_at' => $issued['expires_at'], 'site' => $website->public_id, 'mode' => $website->tracking_mode, 'host' => $website->domain], 200, ['Cache-Control' => 'no-store']);
    }
}
