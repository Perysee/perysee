<?php

declare(strict_types=1);

namespace Perysee\Modules\Annotations;

use App\Http\Controllers\Controller;
use App\Models\Website;
use App\Modules\ModuleSettings;
use App\Support\Ui;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Response;

class NoteController extends Controller
{
    public function index(Website $website, Notes $notes): Response
    {
        $this->authorizeSite('view', $website);

        return Ui::render('module:annotations', [
            'website' => $website,
            'notes' => $notes->forSite($website, 200),
            'canWrite' => Gate::allows('update', $website),
            'noteLinks' => ['store' => route('annotations.notes.store', $website)],
        ]);
    }

    public function store(Request $request, Website $website): RedirectResponse
    {
        $this->authorizeSite('update', $website);
        $data = $request->validate([
            'body' => ['required', 'string', 'max:280'],
            'noted_on' => ['required', 'date_format:Y-m-d', 'date'],
        ]);
        DB::table('annotations_notes')->insert($data + ['website_id' => $website->id, 'user_id' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);

        return redirect()->route('annotations.site', $website);
    }

    public function destroy(Website $website, int $note): RedirectResponse
    {
        $this->authorizeSite('update', $website);
        abort_unless(DB::table('annotations_notes')->where('website_id', $website->id)->where('id', $note)->delete(), 404);

        return redirect()->route('annotations.site', $website);
    }

    public function settings(Request $request, ModuleSettings $settings): RedirectResponse
    {
        abort_unless($request->user()->is_admin, 403);
        $data = $request->validate(['retention_days' => ['required', 'integer', Rule::in(range(30, 3650))]]);
        $settings->set(Notes::MODULE, 'retention_days', (int) $data['retention_days']);

        return redirect()->route('modules.settings', Notes::MODULE)->with('status', 'Retention saved.');
    }

    private function authorizeSite(string $ability, Website $website): void
    {
        abort_if($website->deleting_at, 404);
        Gate::authorize($ability, $website);
    }
}
