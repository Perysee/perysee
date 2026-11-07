<?php

declare(strict_types=1);

namespace App\Modules;

use App\Models\Website;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Where an enabled module is active: the whole instance, or only some sites.
 * A site-limited module stays available on pages that belong to no site, such as its own settings.
 */
final class ModuleScope
{
    /** @var array{limited: array<string, true>, sites: array<string, list<int>>}|null */
    private ?array $state = null;

    public function coversAllSites(string $module): bool
    {
        return ! isset($this->state()['limited'][$module]);
    }

    public function isActiveFor(string $module, ?Website $site): bool
    {
        return $site === null || $this->coversAllSites($module) || in_array($site->id, $this->state()['sites'][$module] ?? [], true);
    }

    /**
     * Same as {@see isActiveFor()} for a site known by its id only, such as the one of a domain event.
     */
    public function isActiveForSiteId(string $module, int $siteId): bool
    {
        return $this->coversAllSites($module) || in_array($siteId, $this->state()['sites'][$module] ?? [], true);
    }

    /**
     * @return list<int>
     */
    public function siteIds(string $module): array
    {
        return $this->state()['sites'][$module] ?? [];
    }

    /**
     * An empty list means no site at all, not every site.
     *
     * @param  list<int>  $siteIds
     */
    public function limitTo(string $module, array $siteIds): void
    {
        $existing = Website::query()->whereIn('id', $siteIds)->whereNull('deleting_at')->pluck('id')->all();
        $now = now();
        DB::transaction(function () use ($module, $existing, $now): void {
            DB::table('module_scopes')->upsert([['module_id' => $module, 'all_sites' => false, 'created_at' => $now, 'updated_at' => $now]], ['module_id'], ['all_sites', 'updated_at']);
            DB::table('module_sites')->where('module_id', $module)->delete();
            DB::table('module_sites')->insert(array_map(fn (int $id): array => ['module_id' => $module, 'website_id' => $id], $existing));
        });
        $this->state = null;
    }

    public function allowAllSites(string $module): void
    {
        DB::transaction(function () use ($module): void {
            DB::table('module_sites')->where('module_id', $module)->delete();
            DB::table('module_scopes')->where('module_id', $module)->delete();
        });
        $this->state = null;
    }

    /**
     * @return array{limited: array<string, true>, sites: array<string, list<int>>}
     */
    private function state(): array
    {
        if ($this->state !== null) {
            return $this->state;
        }
        $state = ['limited' => [], 'sites' => []];
        try {
            foreach (DB::table('module_scopes')->where('all_sites', false)->pluck('module_id') as $module) {
                $state['limited'][$module] = true;
            }
            $rows = DB::table('module_sites')->join('websites', 'websites.id', '=', 'module_sites.website_id')
                ->whereNull('websites.deleting_at')->get(['module_sites.module_id', 'module_sites.website_id']);
            foreach ($rows as $row) {
                $state['sites'][$row->module_id][] = (int) $row->website_id;
            }
        } catch (QueryException) {
            // The tables do not exist before the Core migration has run: every module covers every site.
        }

        return $this->state = $state;
    }
}
