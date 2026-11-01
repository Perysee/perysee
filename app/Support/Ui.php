<?php

namespace App\Support;

use App\Models\Website;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class Ui
{
    /** Properties the application shares with every page. A page that sends one of them would replace it for the whole interface. */
    private const SHARED = ['links', 'auth', 'branding', 'modules', 'assets', 'navigation', 'flash', 'currentUrl', 'docsUrl', 'errors'];

    public static function render(string $screen, array $data = []): Response
    {
        foreach (self::SHARED as $key) {
            if (array_key_exists($key, $data)) {
                throw new \LogicException("The page property \"{$key}\" is shared by the application and would replace it on screen \"{$screen}\". Use another name.");
            }
        }
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
        // Displayed as text, so they keep their absolute form: the tracking snippet and the instance settings.
        $verbatim = array_intersect_key($data, array_flip(['snippet', 'settings']));
        $data = $verbatim + self::relativize($data);
        foreach (['websites', 'sites'] as $key) {
            if (isset($data[$key])) {
                $data[$key]->through(fn (Website $website): array => $website->toArray() + ['url' => route('sites.show', $website, false), 'edit_url' => route('sites.edit', $website, false), 'tracking_url' => route('sites.tracking', $website, false), 'favicon_url' => route('sites.favicon', $website, false), 'can_update' => Gate::allows('update', $website)]);
                $data[$key]->withPath(request()->getPathInfo());
            }
        }

        return Inertia::render('Workspace', ['screen' => $screen] + $data);
    }

    /**
     * Turns the links of this application into paths. Nuxt UI renders any link that carries a protocol as an
     * external anchor, which reloads the whole document instead of making an Inertia visit, so every address
     * the interface may follow must be sent without its host.
     */
    public static function relativize(mixed $value): mixed
    {
        $root = rtrim(url('/'), '/');

        return match (true) {
            is_string($value) => str_starts_with($value, $root.'/') ? substr($value, strlen($root)) : $value,
            is_array($value) => array_map(fn (mixed $item): mixed => self::relativize($item), $value),
            default => $value,
        };
    }

    private static function siteLinks(Website $site): array
    {
        $links = [];
        foreach (['filter-options', 'show', 'edit', 'tracking', 'export', 'favicon', 'update', 'destroy'] as $action) {
            $links[$action] = route('sites.'.$action, $site);
        }
        foreach (['pages', 'sources', 'campaigns', 'devices', 'countries', 'events', 'sessions', 'realtime'] as $section) {
            $links[$section] = route('sites.section', [$site, $section]);
        }
        $links['funnels'] = route('sites.funnels.index', $site);
        $links['journeys'] = route('sites.journeys', $site);
        $links['demo'] = $site->is_demo && app()->environment('local') ? route('demo.show', $site) : null;

        return $links;
    }
}
