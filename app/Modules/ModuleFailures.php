<?php

declare(strict_types=1);

namespace App\Modules;

use App\Analytics\InstanceHealth;
use App\Support\Coordination;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Counts what goes wrong inside modules while they run, and puts a module in quarantine when it keeps failing.
 * Quarantine disables the module (and the modules that need it) but keeps its tables and settings, so an
 * administrator can read the reason, fix or replace it, and enable it again.
 *
 * The counters go through {@see Coordination}, so the Redis module can hold them. Reporting never throws:
 * a diagnostic must not replace the failure it describes.
 */
final class ModuleFailures
{
    public const STATE = 'module_quarantine';

    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly Coordination $coordination,
        private readonly InstanceHealth $health,
    ) {}

    /**
     * Records one failure of an enabled module and quarantines it once it fails too often in the time window.
     */
    public function report(string $id, Throwable $exception, string $context): void
    {
        try {
            $module = $this->registry->all()[$id] ?? null;
            if ($module === null || ! $module->enabled) {
                return;
            }
            $this->coordination->failure('module.'.$id);
            if ($this->coordination->throttle('module-log:'.$id, 3, 600)) {
                Log::warning("Module {$id} failed ({$context}).", ['exception' => $exception]);
            }
            $window = (int) config('perysee.module_failures.window', 600);
            $threshold = max(1, (int) config('perysee.module_failures.threshold.'.$module->manifest->stability, 10));
            // The Nth failure inside the window is the one the limiter refuses.
            if (! $this->coordination->throttle($this->windowKey($id), $threshold - 1, $window)) {
                $minutes = max(1, intdiv($window, 60));
                $this->enterQuarantine($id, "Failed {$threshold} times in {$minutes} minutes ({$context}): ".$this->plain($exception->getMessage()));
            }
        } catch (Throwable) {
            // See the class comment.
        }
    }

    /**
     * The module whose files raised the exception, found in the exception or any exception it wraps.
     */
    public function moduleOf(Throwable $exception): ?string
    {
        $folders = [];
        foreach ($this->registry->all() as $id => $module) {
            $path = realpath($module->path);
            if ($path !== false) {
                $folders[$id] = $path.DIRECTORY_SEPARATOR;
            }
        }
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            $files = array_filter([$current->getFile(), ...array_column($current->getTrace(), 'file')], 'is_string');
            foreach ($files as $file) {
                foreach ($folders as $id => $folder) {
                    if (str_starts_with($file, $folder)) {
                        return $id;
                    }
                }
            }
        }

        return null;
    }

    public function total(string $id): int
    {
        return $this->coordination->failures('module.'.$id);
    }

    /**
     * @return array{reason: string, at: string}|null
     */
    public function quarantine(string $id): ?array
    {
        return $this->quarantined()[$id] ?? null;
    }

    /**
     * @return array<string, array{reason: string, at: string}>
     */
    public function quarantined(): array
    {
        $state = $this->health->get(self::STATE, []);

        return is_array($state) ? $state : [];
    }

    /**
     * Called when an administrator enables a module: its record is cleared and its failure window starts again.
     */
    public function release(string $id): void
    {
        $state = $this->quarantined();
        if (isset($state[$id])) {
            unset($state[$id]);
            $this->health->put(self::STATE, $state);
        }
        Cache::increment($this->generationKey($id));
    }

    /**
     * Modules that failed at least once, or are in quarantine, for the health page.
     *
     * @return list<array{id: string, name: string, failures: int, quarantined: bool, reason: string|null, at: string|null}>
     */
    public function summary(): array
    {
        $quarantined = $this->quarantined();
        $rows = [];
        foreach ($this->registry->all() as $id => $module) {
            $failures = $this->total($id);
            if ($failures === 0 && ! isset($quarantined[$id])) {
                continue;
            }
            $rows[] = [
                'id' => $id,
                'name' => $module->manifest->name,
                'failures' => $failures,
                'quarantined' => isset($quarantined[$id]),
                'reason' => $quarantined[$id]['reason'] ?? null,
                'at' => $quarantined[$id]['at'] ?? null,
            ];
        }

        return $rows;
    }

    private function enterQuarantine(string $id, string $reason): void
    {
        $state = $this->quarantined();
        $at = now('UTC')->toIso8601String();
        foreach ($this->dependentsFirst($id) as $dependent) {
            $this->registry->disable($dependent);
            $state[$dependent] = ['reason' => "Needs {$id}, which was put in quarantine.", 'at' => $at];
        }
        $this->registry->disable($id);
        $state[$id] = ['reason' => $reason, 'at' => $at];
        $this->health->put(self::STATE, $state);
        Log::error("Module {$id} was put in quarantine: {$reason}");
    }

    /**
     * Enabled modules that need $id, directly or not, deepest first, so each can be disabled in turn.
     *
     * @return list<string>
     */
    private function dependentsFirst(string $id): array
    {
        $order = [];
        $visit = function (string $parent) use (&$visit, &$order): void {
            foreach ($this->registry->enabled() as $other) {
                $dependent = $other->manifest->id;
                if (in_array($parent, $other->manifest->requires, true) && ! in_array($dependent, $order, true)) {
                    $visit($dependent);
                    $order[] = $dependent;
                }
            }
        };
        $visit($id);

        return $order;
    }

    private function windowKey(string $id): string
    {
        return 'module-failure:'.$id.':'.(int) Cache::get($this->generationKey($id), 0);
    }

    private function generationKey(string $id): string
    {
        return 'module-failure-generation:'.$id;
    }

    private function plain(string $text): string
    {
        return trim(mb_substr(preg_replace('/\p{Cc}+/u', ' ', $text) ?? '', 0, 300));
    }
}
