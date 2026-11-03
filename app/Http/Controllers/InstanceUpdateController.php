<?php

namespace App\Http\Controllers;

use App\Analytics\InstanceHealth;
use App\Updates\ReleaseFeed;
use App\Updates\UpdateStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The administration side of updates. It never changes files: asking for an update only leaves a note that the
 * scheduler, which runs on the command line with its own limits, picks up within a minute.
 */
class InstanceUpdateController extends Controller
{
    public function check(Request $request, ReleaseFeed $feed): RedirectResponse
    {
        abort_unless($request->user()->is_admin, 403);
        if (! $feed->enabled()) {
            throw ValidationException::withMessages(['update' => 'Update checks are disabled on this instance (PERYSEE_UPDATE_CHECK).']);
        }
        $state = $feed->refresh();
        if ($state['error'] !== null) {
            throw ValidationException::withMessages(['update' => $state['error']]);
        }

        return back()->with('status', $feed->available() === null ? 'Perysee is up to date.' : 'A new version is available.');
    }

    public function request(Request $request, ReleaseFeed $feed, UpdateStatus $status, InstanceHealth $health): RedirectResponse
    {
        abort_unless($request->user()->is_admin, 403);
        $data = $request->validate(['password' => ['required', 'current_password'], 'dump' => ['required', 'in:auto,native,php']]);
        $release = $feed->available();
        if ($release === null) {
            throw ValidationException::withMessages(['update' => 'There is no newer release to install. Check for updates first.']);
        }
        if (in_array($status->current()['state'], ['running', 'needs_attention'], true)) {
            throw ValidationException::withMessages(['update' => 'An update is running or needs attention first.']);
        }
        $heartbeat = $health->get('heartbeat');
        if ($heartbeat === null || now('UTC')->diffInMinutes($heartbeat, true) >= 10) {
            throw ValidationException::withMessages(['update' => 'The scheduler is not running, so nobody would start the update. Fix the cron first, or run `php artisan perysee:update` in a terminal.']);
        }
        $status->request($release->tag, $data['dump'], $request->user()->id);

        return back()->with('status', "Update to {$release->version()} requested. It starts within a minute; the site is unavailable for its duration.");
    }

    public function cancel(Request $request, UpdateStatus $status): RedirectResponse
    {
        abort_unless($request->user()->is_admin, 403);
        $status->clearRequest();

        return back()->with('status', 'The update request was cancelled.');
    }
}
