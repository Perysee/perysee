<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;

class GuideController extends Controller
{
    public function show(string $guide): RedirectResponse
    {
        $paths = [
            'application' => '/getting-started/shared-hosting',
            'tracking' => '/tracking/installation',
            'privacy' => '/tracking/privacy',
        ];
        abort_unless(isset($paths[$guide]), 404);

        return redirect()->away(rtrim(config('perysee.docs_url'), '/').$paths[$guide]);
    }
}
