<?php

namespace App\Http\Controllers;

use App\Models\Website;
use App\SiteFavicon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class SiteFaviconController extends Controller
{
    public function __invoke(Request $request, Website $website, SiteFavicon $favicons): Response|RedirectResponse
    {
        Gate::authorize('view', $website);
        if ($website->is_demo && preg_match('/^[a-z0-9.-]+$/', $website->domain) === 1 && is_file($path = public_path('demo/'.$website->domain.'.svg'))) {
            return response(file_get_contents($path), 200, [
                'Content-Type' => 'image/svg+xml',
                'Cache-Control' => 'private, max-age=3600',
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "default-src 'none'; sandbox",
            ]);
        }
        $icon = $favicons->forDomain($website->domain);
        if (! $icon) {
            // The interface asks for no content so it can draw the site's initials instead.
            if ($request->boolean('initials')) {
                return response()->noContent()->header('Cache-Control', 'private, max-age=300');
            }

            return redirect('/site-icon.svg')->header('Cache-Control', 'private, max-age=300');
        }
        $response = response($icon['body'], 200, [
            'Content-Type' => $icon['mime'],
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ])->setEtag(hash('sha256', $icon['body']));
        $response->isNotModified($request);

        return $response;
    }
}
