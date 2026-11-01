<?php

declare(strict_types=1);

namespace Perysee\Modules\Example;

use App\Http\Controllers\Controller;
use App\Modules\ModuleSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class GreetingController extends Controller
{
    public function update(Request $request, ModuleSettings $settings): RedirectResponse
    {
        // Routes registered by a module are open to every signed-in user: check the role yourself.
        abort_unless($request->user()->is_admin, 403);
        $data = $request->validate(['greeting' => ['required', 'string', 'max:80', 'regex:/^[^<>\p{Cc}]+$/u']]);
        $settings->set('example', 'greeting', $data['greeting']);

        return redirect()->route('modules.settings', 'example')->with('status', 'Greeting saved.');
    }
}
