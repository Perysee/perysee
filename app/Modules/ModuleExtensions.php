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

    /** @var array<string, Closure(): array<string, mixed>> */
    private array $healthCards = [];

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
     * A status card on the instance health page, resolved each time an administrator opens it.
     *
     * @param  Closure(): array<string, mixed>  $resolver
     */
    public function healthCard(string $module, Closure $resolver): void
    {
        $this->healthCards[$module] = $resolver;
    }

    /**
     * Each card is reduced to a title, a status and a short list of label/value lines of plain text, so a module
     * can report a state but never inject markup. A card that fails or has no title is left out.
     *
     * @return list<array{module: string, title: string, status: string, items: list<array{label: string, value: string}>}>
     */
    public function healthCards(): array
    {
        $cards = [];
        foreach ($this->healthCards as $module => $resolver) {
            try {
                $card = $resolver();
            } catch (Throwable $exception) {
                Log::warning("Module {$module} could not provide its health card.", ['exception' => $exception]);
                app(ModuleFailures::class)->report($module, $exception, 'health card');

                continue;
            }
            $title = is_string($card['title'] ?? null) ? $this->plain($card['title'], 60) : '';
            if ($title === '') {
                continue;
            }
            $items = [];
            foreach (is_array($card['items'] ?? null) ? $card['items'] : [] as $item) {
                $value = is_array($item) ? ($item['value'] ?? null) : null;
                if (! is_string($item['label'] ?? null) || ! (is_string($value) || is_int($value) || is_float($value))) {
                    continue;
                }
                $items[] = ['label' => $this->plain($item['label'], 60), 'value' => $this->plain((string) $value, 200)];
            }
            $cards[] = ['module' => $module, 'title' => $title, 'status' => ($card['status'] ?? null) === 'ok' ? 'ok' : 'attention', 'items' => array_slice($items, 0, 12)];
        }

        return $cards;
    }

    private function plain(string $text, int $limit): string
    {
        return trim(mb_substr(preg_replace('/\p{Cc}+/u', ' ', $text) ?? '', 0, $limit));
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
                app(ModuleFailures::class)->report($module, $exception, 'page data');
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
                app(ModuleFailures::class)->report($module, $exception, 'branding');

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
