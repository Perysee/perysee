<?php

declare(strict_types=1);

namespace App\Modules;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * @internal Not part of the module API: modules must not call it, and it may change in any release.
 */
final class ModuleLoader
{
    /** @var array<string, string> */
    private array $failures = [];

    /** @var array<string, true> */
    private array $loaded = [];

    public function __construct(private Application $app, private ModuleRegistry $registry) {}

    /**
     * Registers the autoloader and service provider of every enabled module.
     * A failing module is reported and skipped: it must never stop the core or the collector.
     */
    public function load(): void
    {
        if (! config('perysee.modules_enabled')) {
            return;
        }
        foreach ($this->registry->enabled() as $id => $module) {
            if (isset($this->loaded[$id])) {
                continue;
            }
            $this->loaded[$id] = true;
            try {
                $this->autoload($module);
                $provider = 'Perysee\\Modules\\'.$module->manifest->namespaceSegment().'\\ModuleServiceProvider';
                if (! class_exists($provider)) {
                    throw new InvalidManifest("Module {$id} has no {$provider} class.");
                }
                $this->app->register(is_subclass_of($provider, ModuleProvider::class) ? new $provider($this->app, $module) : $provider);
            } catch (Throwable $exception) {
                $this->failures[$id] = $exception->getMessage();
                Log::warning("Module {$id} could not be loaded.", ['exception' => $exception]);
                app(ModuleFailures::class)->report($id, $exception, 'loading');
            }
        }
    }

    /**
     * Load errors by module id.
     *
     * @return array<string, string>
     */
    public function failures(): array
    {
        return $this->failures;
    }

    private function autoload(Module $module): void
    {
        $prefix = 'Perysee\\Modules\\'.$module->manifest->namespaceSegment().'\\';
        $source = $module->path.'/src/';
        spl_autoload_register(static function (string $class) use ($prefix, $source): void {
            if (! str_starts_with($class, $prefix)) {
                return;
            }
            $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
            if (preg_match('/^[A-Za-z0-9_\/]+$/D', $relative) && is_file($source.$relative.'.php')) {
                require $source.$relative.'.php';
            }
        });
    }
}
