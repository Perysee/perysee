<?php

declare(strict_types=1);

namespace Perysee\Modules\Annotations;

use App\Modules\ModuleProvider;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;

class ModuleServiceProvider extends ModuleProvider
{
    public function boot(): void
    {
        $this->loadModuleRoutes(__DIR__.'/../routes.php');
        $this->addNavigation('site', 'Annotations', 'i-tabler-note', 'annotations.site');
        $this->commands([PruneCommand::class]);
        $this->schedule(fn (Schedule $schedule) => $schedule->command('annotations:prune')->daily()->description('annotations prune'));
        $this->shareData(fn (Request $request): array => $this->app->make(Notes::class)->pageData($request->user(), $request->route('website')));
    }
}
