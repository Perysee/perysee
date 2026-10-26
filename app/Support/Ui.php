<?php

namespace App\Support;

use App\Models\Website;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class Ui
{
    public static function render(string $screen, array $data = []): Response
    {
        $site = $data['website'] ?? null;
        if ($site instanceof Website) {
            $data['canUpdate'] = $site->exists && Gate::allows('update', $site);
            $data['siteLinks'] = $site->exists ? self::siteLinks($site) : null;
        }
        if ($screen === 'dashboard') {
            $data['period'] = collect($data['summaries'])->first()['period'] ?? ['from' => now('UTC')->subDays(6)->toDateString(), 'to' => now('UTC')->toDateString()];
            $data['today'] = now('UTC')->toDateString();
        }
        if ($screen === 'sites.form') {
            $data['timezones'] = \DateTimeZone::listIdentifiers();
        }
        if (isset($data['period'], $site)) {
            $data['today'] = now($site->timezone)->toDateString();
        }
        if ($screen === 'sites.tracking') {
            $data['snippet'] = '<script defer crossorigin="anonymous" referrerpolicy="no-referrer" src="'.route('tracker.script').'" data-site="'.$site->public_id.'" data-mode="'.$site->tracking_mode.'" data-consent-required="'.($site->consent_required ? 'true' : 'false').'"'.($site->excluded_paths ? ' data-exclude-paths="'.e(implode(',', $site->excluded_paths)).'"' : '').'></script>';
        }
        if ($screen === 'install' && $data['authorized'] && ! $data['migrated']) {
            $data['databaseDefaults'] = collect(config('database.connections.mariadb'))->only(['host', 'port', 'database', 'username'])->all();
        }
        foreach (['websites', 'sites'] as $key) {
            if (isset($data[$key])) {
                $data[$key]->through(fn (Website $website): array => $website->toArray() + ['url' => route('sites.show', $website), 'edit_url' => route('sites.edit', $website), 'favicon_url' => route('sites.favicon', $website), 'can_update' => Gate::allows('update', $website)]);
            }
        }

        return Inertia::render('Workspace', ['screen' => $screen] + $data);
    }

    private static function siteLinks(Website $site): array
    {
        $links = [];
        foreach (['show', 'edit', 'tracking', 'export', 'favicon', 'update', 'destroy'] as $action) {
            $links[$action] = route('sites.'.$action, $site);
        }
        foreach (['pages', 'sources', 'campaigns', 'devices', 'countries', 'events', 'sessions', 'realtime'] as $section) {
            $links[$section] = route('sites.section', [$site, $section]);
        }
        $links['demo'] = $site->is_demo && app()->environment('local') ? route('demo.show', $site) : null;

        return $links;
    }
}
