<?php

declare(strict_types=1);

namespace App\Modules;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Key-value settings owned by a module. Values are stored as JSON, so any scalar or array round-trips.
 *
 * @api Part of module API 1.
 */
final class ModuleSettings
{
    /** @var array<string, array<string, mixed>> */
    private array $cache = [];

    public function get(string $module, string $key, mixed $default = null): mixed
    {
        return $this->all($module)[$key] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(string $module): array
    {
        return $this->cache[$module] ??= $this->read($module);
    }

    public function set(string $module, string $key, mixed $value): void
    {
        $now = now();
        DB::table('module_settings')->upsert(
            [['module_id' => $module, 'key' => $key, 'value' => json_encode($value, JSON_THROW_ON_ERROR), 'created_at' => $now, 'updated_at' => $now]],
            ['module_id', 'key'],
            ['value', 'updated_at'],
        );
        unset($this->cache[$module]);
    }

    public function forget(string $module, string $key): void
    {
        DB::table('module_settings')->where('module_id', $module)->where('key', $key)->delete();
        unset($this->cache[$module]);
    }

    /**
     * @return array<string, mixed>
     */
    private function read(string $module): array
    {
        try {
            return DB::table('module_settings')->where('module_id', $module)->pluck('value', 'key')
                ->map(fn (string $value): mixed => json_decode($value, true))->all();
        } catch (QueryException) {
            // The table does not exist before the migration has run: modules fall back to their defaults.
            return [];
        }
    }
}
