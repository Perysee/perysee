<?php

declare(strict_types=1);

namespace Perysee\Modules\WhiteLabel;

use App\Modules\ModuleProvider;
use Illuminate\Http\Request;

class ModuleServiceProvider extends ModuleProvider
{
    public function boot(): void
    {
        $this->loadModuleRoutes(__DIR__.'/../routes.php');
        $this->loadPublicRoutes(__DIR__.'/../public-routes.php');
        $this->brand(fn (): array => $this->app->make(Branding::class)->forDisplay());
        $this->shareData(fn (Request $request): array => $request->user()?->is_admin ? $this->app->make(Branding::class)->forAdministrator() : []);
    }
}
