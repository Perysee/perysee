<?php

namespace App\Analytics\Diagnostics;

use App\Models\Website;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Tells, for the sites of one page of a list, when each last received an event and whether it went quiet.
 * One grouped read for the whole page, whatever the number of sites (at most one page).
 */
class SiteActivity
{
    /** Adds `last_event_at`, `silent` and `verify_url` to every site of the page. */
    public static function annotate(LengthAwarePaginator $sites): void
    {
        $items = collect($sites->items());
        $last = $items->isEmpty() ? collect() : DB::table('events')->whereIn('website_id', $items->pluck('id'))->groupBy('website_id')->selectRaw('website_id, max(received_at) as last_at')->pluck('last_at', 'website_id');
        $limit = now('UTC')->subDays(max(1, (int) config('perysee.silent_site_days', 3)));
        foreach ($items as $site) {
            /** @var Website $site */
            $at = $last[$site->id] ?? null;
            $site->setAttribute('last_event_at', $at ? Carbon::parse($at, 'UTC')->toIso8601String() : null);
            // Quiet only after having been heard: a site never measured is "waiting", which has its own prompt.
            $site->setAttribute('silent', ($at !== null && Carbon::parse($at, 'UTC')->lt($limit)) || ($at === null && $site->first_collected_at !== null));
            $site->setAttribute('verify_url', route('sites.verify', $site, false));
        }
    }
}
