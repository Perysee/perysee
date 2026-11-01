<?php

namespace App\Http\Controllers;

use App\Models\Website;
use App\Modules\Module;
use App\Modules\ModuleLoader;
use App\Modules\ModuleRegistry;
use App\Modules\ModuleScope;
use App\Support\Ui;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Response;
use RuntimeException;

class ModuleController extends Controller
{
    public function index(Request $request, ModuleRegistry $registry, ModuleLoader $loader, ModuleScope $scope): Response
    {
        $this->authorizeAdministrator($request);
        $failures = $loader->failures();
        $enabled = $registry->enabled();
        $modules = array_map(fn (Module $module): array => [
            'id' => $module->manifest->id,
            'name' => $module->manifest->name,
            'version' => $module->manifest->version,
            'description' => $module->manifest->description,
            'core' => $module->manifest->core,
            'enabled' => $module->enabled,
            'compatible' => $module->manifest->isCompatibleWith(config('perysee.version')),
            'requires' => $module->manifest->requires,
            'missing_dependencies' => array_values(array_filter($module->manifest->requires, fn (string $id): bool => ! isset($enabled[$id]))),
            'dependents' => array_values(array_keys(array_filter($enabled, fn (Module $other): bool => in_array($module->manifest->id, $other->manifest->requires, true)))),
            'failure' => $failures[$module->manifest->id] ?? null,
            'installed' => $module->installed,
            'scope' => ['all' => $scope->coversAllSites($module->manifest->id), 'count' => count($scope->siteIds($module->manifest->id))],
            'links' => ['enable' => route('modules.enable', $module->manifest->id), 'disable' => route('modules.disable', $module->manifest->id), 'settings' => route('modules.settings', $module->manifest->id)],
        ], array_values($registry->all()));
        $invalid = [];
        foreach ($registry->invalid() as $directory => $reason) {
            $invalid[] = ['directory' => $directory, 'reason' => $reason];
        }

        return Ui::render('modules', ['installed' => $modules, 'invalid' => $invalid, 'path' => config('perysee.modules_path')]);
    }

    public function settings(Request $request, ModuleRegistry $registry, ModuleLoader $loader, ModuleScope $scope, string $module): Response
    {
        $this->authorizeAdministrator($request);
        $installed = $registry->all()[$module] ?? abort(404);
        $sites = $this->reachableSites($request);
        $visibleIds = $sites->pluck('id')->all();
        $selected = $scope->siteIds($module);
        $shown = array_values(array_intersect($selected, $visibleIds));

        return Ui::render('modules.settings', [
            'module' => [
                'id' => $installed->manifest->id,
                'name' => $installed->manifest->name,
                'version' => $installed->manifest->version,
                'description' => $installed->manifest->description,
                'enabled' => $installed->enabled,
                'installed' => $installed->installed,
                'compatible' => $installed->manifest->isCompatibleWith(config('perysee.version')),
                'failure' => $loader->failures()[$module] ?? null,
            ],
            'scope' => ['all' => $scope->coversAllSites($module), 'site_ids' => $shown, 'hidden' => count($selected) - count($shown)],
            'siteChoices' => $sites->map(fn ($site): array => ['id' => $site->id, 'name' => $site->name, 'domain' => $site->domain])->values()->all(),
            'moduleLinks' => ['save' => route('modules.scope', $module), 'back' => route('modules.index')],
        ]);
    }

    /**
     * Sites already selected that this administrator cannot reach are kept untouched: an instance administrator
     * has no implicit access to sites they are not a member of, and must not lose another person's selection.
     */
    public function updateScope(Request $request, ModuleRegistry $registry, ModuleScope $scope, string $module): RedirectResponse
    {
        $this->authorizeAdministrator($request);
        abort_unless(isset($registry->all()[$module]), 404);
        $visibleIds = $this->reachableSites($request)->pluck('id')->all();
        $data = $request->validate([
            'scope' => ['required', Rule::in(['all', 'sites'])],
            'sites' => ['array'],
            'sites.*' => ['integer', Rule::in($visibleIds)],
        ]);
        if ($data['scope'] === 'all') {
            $scope->allowAllSites($module);
        } else {
            $scope->limitTo($module, array_values(array_unique([...array_map('intval', $data['sites'] ?? []), ...array_diff($scope->siteIds($module), $visibleIds)])));
        }

        return redirect()->route('modules.settings', $module)->with('status', 'Availability saved.');
    }

    public function enable(Request $request, ModuleRegistry $registry, string $module): RedirectResponse
    {
        return $this->change($request, fn () => $registry->enable($module), "Module {$module} enabled.");
    }

    public function disable(Request $request, ModuleRegistry $registry, string $module): RedirectResponse
    {
        return $this->change($request, fn () => $registry->disable($module), "Module {$module} disabled.");
    }

    private function change(Request $request, callable $change, string $success): RedirectResponse
    {
        $this->authorizeAdministrator($request);
        try {
            $change();
        } catch (RuntimeException $exception) {
            return redirect()->route('modules.index')->with('error', $exception->getMessage());
        }

        return redirect()->route('modules.index')->with('status', $success);
    }

    /**
     * @return Collection<int, Website>
     */
    private function reachableSites(Request $request)
    {
        return $request->user()->websites()->whereNull('deleting_at')->orderBy('name')->get(['websites.id', 'websites.name', 'websites.domain']);
    }

    private function authorizeAdministrator(Request $request): void
    {
        abort_unless($request->user()->is_admin, 403);
    }
}
