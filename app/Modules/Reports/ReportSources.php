<?php

declare(strict_types=1);

namespace App\Modules\Reports;

use App\Mail\InstanceMailer;
use App\Modules\Contracts\ReportSource;
use App\Modules\ModuleFailures;
use App\Modules\ModuleProvider;
use App\Modules\ModuleRegistry;
use App\Modules\ModuleScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * The sources of figures for periodic reports: the Core's "core.traffic" and those modules declare. A key is
 * "<module id>.<name>" and is declared once. {@see collect()} asks every source that is usable for the site, isolates
 * the ones that fail, and caps the number of sections, so a digest built on it cannot be broken or flooded by a module.
 *
 * @api Modules declare sources with {@see ModuleProvider::reportSource()}.
 */
final class ReportSources
{
    public const CORE = 'core';

    /** Sections kept per source, and over all sources, in one report. */
    public const SECTIONS_PER_SOURCE = 8;

    public const SECTIONS_TOTAL = 40;

    private const KEY_PATTERN = '/^[a-z0-9](?:[a-z0-9-]{0,38}[a-z0-9])?\.[a-z0-9][a-z0-9._-]{0,39}$/D';

    /** @var array<string, array{module: string, source: ReportSource|class-string<ReportSource>}> */
    private array $sources;

    public function __construct()
    {
        $this->sources = ['core.traffic' => ['module' => self::CORE, 'source' => CoreTrafficSource::class]];
    }

    /**
     * @param  ReportSource|class-string<ReportSource>  $source
     */
    public function register(string $module, string $key, ReportSource|string $source): void
    {
        if (! preg_match(self::KEY_PATTERN, $key) || ! str_starts_with($key, $module.'.')) {
            throw new InvalidArgumentException("Report source key \"{$key}\" must be \"{$module}.<name>\" (letters, digits, dashes, dots, underscores).");
        }
        if (isset($this->sources[$key])) {
            throw new InvalidArgumentException("Report source \"{$key}\" is already declared.");
        }
        if (is_string($source) && ! is_subclass_of($source, ReportSource::class)) {
            throw new InvalidArgumentException("\"{$source}\" does not implement the ReportSource contract.");
        }
        $this->sources[$key] = ['module' => $module, 'source' => $source];
    }

    /**
     * The sections of every usable source for a site and a period, in declaration order, the Core's first.
     *
     * @return list<array{key: string, label: string, sections: list<ReportSection>}>
     */
    public function collect(int $siteId, Carbon $from, Carbon $to): array
    {
        $report = [];
        $total = 0;
        foreach ($this->sources as $key => $entry) {
            if ($total >= self::SECTIONS_TOTAL || ! $this->usable($entry['module'], $siteId)) {
                continue;
            }
            try {
                $source = is_string($entry['source']) ? app($entry['source']) : $entry['source'];
                $label = trim(mb_substr(preg_replace('/\p{Cc}+/u', ' ', $source->label()) ?? '', 0, 60));
                $sections = [];
                foreach ($source->sections($siteId, $from, $to) as $section) {
                    // Only the value object is trusted: it is what removes control characters and caps the lines.
                    if ($section instanceof ReportSection) {
                        $sections[] = $section;
                    }
                }
                $sections = array_slice($sections, 0, min(self::SECTIONS_PER_SOURCE, self::SECTIONS_TOTAL - $total));
            } catch (Throwable $exception) {
                $clean = new RuntimeException(InstanceMailer::scrub($exception->getMessage()));
                Log::warning("Report source {$key} failed: ".class_basename($exception).': '.$clean->getMessage());
                if ($entry['module'] !== self::CORE) {
                    app(ModuleFailures::class)->report($entry['module'], $clean, 'report source '.$key);
                }

                continue;
            }
            $total += count($sections);
            $report[] = ['key' => $key, 'label' => $label, 'sections' => $sections];
        }

        return $report;
    }

    private function usable(string $module, int $siteId): bool
    {
        if ($module === self::CORE) {
            return true;
        }

        return (app(ModuleRegistry::class)->all()[$module]->enabled ?? false) && app(ModuleScope::class)->isActiveForSiteId($module, $siteId);
    }
}
