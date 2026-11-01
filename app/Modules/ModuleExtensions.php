<?php

declare(strict_types=1);

namespace App\Modules;

use App\Models\Website;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * What enabled modules contribute to the existing interface: navigation entries, per-module page data
 * and the instance branding. The core decides where each contribution appears and validates it.
 */
final class ModuleExtensions
{
    public const ZONES = ['workspace', 'admin', 'site'];

    public const DEFAULT_BRANDING = ['name' => 'Perysee', 'logo' => null, 'accent' => null];

    public function __construct(private ModuleScope $scope) {}

    /** @var array<string, list<array{module: string, label: string, icon: string, route: string}>> */
    private array $navigation = ['workspace' => [], 'admin' => [], 'site' => []];

    /** @var array<string, Closure(Request): array<string, mixed>> */
    private array $data = [];

    /** @var array<string, Closure(): array<string, mixed>> */
    private array $branding = [];

    public function addNavigation(string $module, string $zone, string $label, string $icon, string $route): void
    {
        if (! in_array($zone, self::ZONES, true)) {
            throw new InvalidArgumentException("Unknown navigation zone \"{$zone}\". Use one of: ".implode(', ', self::ZONES).'.');
        }
        $this->navigation[$zone][] = ['module' => $module, 'label' => $label, 'icon' => $icon, 'route' => $route];
    }

    /**
     * @param  Closure(Request): array<string, mixed>  $resolver
     */
    public function shareData(string $module, Closure $resolver): void
    {
        $this->data[$module] = $resolver;
    }

    /**
     * @param  Closure(): array<string, mixed>  $resolver
     */
    public function brand(string $module, Closure $resolver): void
    {
        $this->branding[$module] = $resolver;
    }

    /**
     * @return array<string, list<array{module: string, label: string, icon: string, url: string}>>
     */
    public function navigation(bool $includeAdmin, ?Website $site = null): array
    {
        $zones = [];
        foreach (self::ZONES as $zone) {
            $items = match ($zone) {
                'admin' => $includeAdmin ? $this->navigation[$zone] : [],
                // Site entries exist only inside a site, and only for modules active on it.
                'site' => $site ? array_filter($this->navigation[$zone], fn (array $item): bool => $this->scope->isActiveFor($item['module'], $site)) : [],
                default => $this->navigation[$zone],
            };
            $zones[$zone] = array_values(array_map(fn (array $item): array => [
                'module' => $item['module'],
                'label' => $item['label'],
                'icon' => $item['icon'],
                'url' => $zone === 'site' ? route($item['route'], $site) : route($item['route']),
            ], $items));
        }

        return $zones;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function data(Request $request, ?Website $site = null): array
    {
        $shared = [];
        foreach ($this->data as $module => $resolver) {
            if (! $this->scope->isActiveFor($module, $site)) {
                continue;
            }
            try {
                $shared[$module] = $resolver($request);
            } catch (Throwable $exception) {
                Log::warning("Module {$module} could not provide its page data.", ['exception' => $exception]);
            }
        }

        return $shared;
    }

    /**
     * The first module with a valid branding wins. Each value is validated here, so a module can only
     * change the name, an image address and one colour, never inject markup or styles.
     *
     * @return array{name: string, logo: string|null, accent: string|null}
     */
    public function branding(?Website $site = null): array
    {
        foreach ($this->branding as $module => $resolver) {
            // A site-limited module brands only the pages of its sites, never the sign-in page or instance pages.
            if (! $this->scope->coversAllSites($module) && ($site === null || ! $this->scope->isActiveFor($module, $site))) {
                continue;
            }
            try {
                $custom = $resolver();
            } catch (Throwable $exception) {
                Log::warning("Module {$module} could not provide its branding.", ['exception' => $exception]);

                continue;
            }
            $name = is_string($custom['name'] ?? null) ? trim($custom['name']) : '';
            $logo = $custom['logo'] ?? null;
            $accent = $custom['accent'] ?? null;

            return [
                'name' => $name !== '' && mb_strlen($name) <= 40 && ! preg_match('/[\p{Cc}<>]/u', $name) ? $name : self::DEFAULT_BRANDING['name'],
                'logo' => is_string($logo) && preg_match('#^(https://|/)[^\s"\'<>\\\\]+$#D', $logo) ? $logo : null,
                'accent' => is_string($accent) && preg_match('/^#[0-9a-fA-F]{6}$/D', $accent) ? $accent : null,
            ];
        }

        return self::DEFAULT_BRANDING;
    }
}
