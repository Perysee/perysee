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
        $icon = $favicons->forDomain($website->domain);
        if (! $icon) {
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
