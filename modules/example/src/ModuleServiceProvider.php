<?php

declare(strict_types=1);

namespace Perysee\Modules\Example;

use App\Modules\ModuleProvider;
use App\Modules\ModuleSettings;
use Illuminate\Http\Request;

/**
 * The entry point of a module. Perysee boots it only while the module is enabled.
 */
class ModuleServiceProvider extends ModuleProvider
{
    public function boot(): void
    {
        // 1. Routes, served under /modules/example for signed-in users (see routes.php).
        $this->loadModuleRoutes(__DIR__.'/../routes.php');

        // 2. Data for this module's screens. The interface reads it as modules.data.example.
        //    It is shared on every page, so keep it small and never include secrets.
        $this->shareData(fn (Request $request): array => [
            'greeting' => $this->app->make(ModuleSettings::class)->get('example', 'greeting', 'Hello from the Example module.'),
            // Only administrators receive the address used to save the greeting.
            'save' => $request->user()?->is_admin ? route('example.greeting') : null,
        ]);

        // Other things a module can do from here (see the module guide in the documentation):
        //   $this->loadPublicRoutes($file);                 routes without a session
        //   $this->addNavigation('site', 'Label', 'i-tabler-note', 'example.route');
        //   $this->schedule(fn ($schedule) => $schedule->command('example:run')->daily());
        //   $this->commands([RunCommand::class]);
        //   $this->brand(fn () => ['name' => 'My analytics']);
    }
}
