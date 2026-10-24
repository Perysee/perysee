<?php

namespace App\Http\Controllers;

use App\Models\Website;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class DemoController extends Controller
{
    public function show(Request $request, Website $website): View
    {
        abort_unless(app()->environment('local') && $website->is_demo, 404);
        Gate::authorize('view', $website);
        $page = in_array($request->query('page'), ['about', 'pricing'], true) ? $request->query('page') : 'home';

        return view('demo', compact('website', 'page'));
    }
}
