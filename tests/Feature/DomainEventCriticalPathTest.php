<?php

namespace Tests\Feature;

use App\Installation\InstallationState;
use App\Models\Website;
use App\Modules\Events\AggregationCompleted;
use App\Modules\Events\BacklogExceeded;
use App\Modules\Events\DomainEvents;
use App\Modules\Events\GoalConverted;
use App\Modules\Events\HeartbeatMissed;
use App\Modules\ModuleFailures;
use App\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\RateLimiter;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Collection is the one path that must never feel a module: no event is published there, no listener runs, and the
 * work done per request does not change when a module listens.
 */
class DomainEventCriticalPathTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(InstallationState::class)->exclusive(fn () => app(InstallationState::class)->complete());
        $modules = $this->installationDirectory.'/modules';
        mkdir($modules);
        File::ensureDirectoryExists($modules.'/watcher');
        file_put_contents($modules.'/watcher/module.json', json_encode(['id' => 'watcher', 'name' => 'Watcher', 'version' => '1.0.0', 'core' => '0.1.0']));
        config(['perysee.modules_path' => $modules, 'perysee.version' => '0.4.0']);
        Cache::flush();
        app()->forgetInstance(ModuleRegistry::class);
        app()->forgetInstance(ModuleFailures::class);
        app()->forgetInstance(DomainEvents::class);
        app(ModuleRegistry::class)->enable('watcher');
    }

    /** @return list<string> */
    private function collect(Website $site, string $session): array
    {
        RateLimiter::clear('collect');
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = preg_replace('/\(\?(, ?\?)*\)/', '(?)', $query->sql);
        });
        $this->withHeader('Origin', 'https://'.$site->domain)->postJson('/api/collect', ['v' => 1, 'site' => $site->public_id, 'type' => 'pageview', 'mode' => 'sessions', 'host' => $site->domain, 'path' => '/pricing', 'consent' => true, 'session' => $session])->assertNoContent();

        return $queries;
    }

    public function test_collecting_never_calls_a_listener_and_costs_the_same_with_or_without_one(): void
    {
        $site = Website::factory()->create(['tracking_mode' => 'sessions']);
        $this->collect($site, str_repeat('c', 32)); // the first collection of a site also stamps the site
        $without = $this->collect($site, str_repeat('a', 32));

        $calls = 0;
        foreach ([AggregationCompleted::class, GoalConverted::class, HeartbeatMissed::class, BacklogExceeded::class] as $event) {
            app(DomainEvents::class)->listen('watcher', $event, function () use (&$calls): void {
                $calls++;
            });
        }
        $with = $this->collect($site, str_repeat('d', 32));

        $this->assertSame(0, $calls);
        $this->assertSame(count($without), count($with), "Queries without a listener:\n".implode("\n", $without)."\n\nwith one:\n".implode("\n", $with));
    }

    public function test_the_collector_and_the_ingestion_code_never_reference_the_event_bus(): void
    {
        $files = [base_path('app/Http/Controllers/TrackingController.php')];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app/Analytics/Ingestion'), RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        $this->assertGreaterThan(1, count($files));
        foreach ($files as $path) {
            $source = file_get_contents($path);
            $this->assertDoesNotMatchRegularExpression('/DomainEvents|Modules\\\\Events|SchedulerWatch|PassEvents|NotificationChannels|ReportSources/', $source, basename($path).' must not touch module events or registries.');
        }
    }
}
