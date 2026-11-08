<?php

namespace Tests\Feature;

use App\Analytics\Aggregation\Segments;
use App\Installation\InstallationState;
use App\Models\Website;
use App\Modules\Events\AggregationCompleted;
use App\Modules\Events\BacklogExceeded;
use App\Modules\Events\ContinuityBreakRecorded;
use App\Modules\Events\DomainEvents;
use App\Modules\Events\GoalConverted;
use App\Modules\Events\HeartbeatMissed;
use App\Modules\ModuleFailures;
use App\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Companion of IdleSchedulerCostTest for module events: listening modules must add almost nothing to a scheduler pass
 * that has nothing to do, and nothing at all when nobody listens.
 */
class DomainEventsCostTest extends TestCase
{
    use RefreshDatabase;

    private const COMMANDS = ['perysee:aggregate --batch=500 --budget-seconds=15', 'perysee:maintenance --limit=1000', 'perysee:rebuild-segments --batch=500', 'perysee:journey-reports --batch=500 --budget=10', 'perysee:update --requested --yes'];

    /** @return list<string> */
    private function idlePass(): array
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        foreach (self::COMMANDS as $command) {
            $this->artisan($command)->assertSuccessful();
        }

        return $queries;
    }

    public function test_listening_modules_add_at_most_one_read_to_an_idle_scheduler_pass(): void
    {
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
        Website::factory()->create();
        app(Segments::class)->rebuild(500);
        $this->idlePass(); // warm-up: first runs write markers that later runs do not

        $alone = count($this->idlePass());
        foreach ([AggregationCompleted::class, GoalConverted::class, HeartbeatMissed::class, BacklogExceeded::class, ContinuityBreakRecorded::class] as $event) {
            app(DomainEvents::class)->listen('watcher', $event, fn () => null);
        }
        $listened = $this->idlePass();

        // The one read is the previous heartbeat, compared to announce a recovery. IdleSchedulerCostTest keeps its own
        // budget (30 on SQLite, 40 on MariaDB) for an instance where no module listens.
        $this->assertLessThanOrEqual($alone + 1, count($listened), implode(' | ', $listened));
    }
}
