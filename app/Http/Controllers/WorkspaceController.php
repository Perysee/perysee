<?php

namespace App\Http\Controllers;

use App\Models\Website;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class WorkspaceController extends Controller
{
    public function dashboard(): View
    {
        return view('placeholder', ['title' => 'Dashboard', 'description' => 'A home for the sites and reports that matter to you.', 'message' => 'Your workspace dashboard is coming. All your websites are already available in Sites.', 'icon' => 'layout-dashboard', 'website' => null]);
    }

    public function pixels(): View
    {
        return view('placeholder', ['title' => 'Pixels', 'description' => 'Manage lightweight tracking pixels from one place.', 'message' => 'Pixel tracking will be available in a future release. You can already measure pageviews with Lueur.', 'icon' => 'grid-dots', 'website' => null]);
    }

    public function settings(Request $request): View
    {
        abort_unless($request->user()->is_admin, 403);

        return view('settings', ['settings' => [
            'Application name' => config('app.name'),
            'Application address' => config('app.url'),
            'Instance timezone' => config('app.timezone'),
            'Raw event retention' => config('perysee.raw_retention_days').' days',
        ]]);
    }

    public function sitePage(Website $website, string $section): View
    {
        Gate::authorize('view', $website);
        $sections = [
            'events' => ['Events', 'Understand the actions people take on your site.', 'Custom event reports are coming. Lueur currently collects pageviews.', 'bolt'],
            'sessions' => ['Sessions', 'Explore visits without confusing sessions with people.', 'Session reports are coming. You can already check observed sessions in Overview when session measurement is enabled.', 'users'],
            'realtime' => ['Realtime', 'See activity as it reaches your instance.', 'The live activity feed is coming. Overview already shows confirmed events received in the last fifteen minutes.', 'clock'],
            'pixels' => ['Pixels', 'A simple tracking pixel for this website.', 'Pixel tracking is not implemented yet. Use Tracking setup to install Lueur in the meantime.', 'grid-dots'],
        ];
        abort_unless(isset($sections[$section]), 404);
        [$title, $description, $message, $icon] = $sections[$section];

        return view('placeholder', compact('title', 'description', 'message', 'icon', 'website'));
    }
}
