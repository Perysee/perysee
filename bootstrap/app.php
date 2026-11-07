<?php

use App\Http\Api\ApiV1Errors;
use App\Http\Middleware\ApiV1Boundary;
use App\Http\Middleware\BootstrapInstallation;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\TrustConfiguredProxies;
use App\Modules\ModuleFailures;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            require base_path('routes/tracking.php');
            require base_path('routes/share.php');
            require base_path('routes/api_v1.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->replace(TrustProxies::class, TrustConfiguredProxies::class);
        $middleware->prepend(BootstrapInstallation::class);
        // read-api: first in line, so the framework's CORS handling never answers for /api/v1
        $middleware->prepend(ApiV1Boundary::class);
        $middleware->trimStrings(except: ['db_password']);
        $middleware->authenticateSessions();
        $middleware->web(append: [HandleInertiaRequests::class]);
        $middleware->redirectUsersTo('/sites');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['current_password', 'password', 'password_confirmation', 'db_password', 'installation_secret']);
        // An exception raised by a module's own files counts against that module (and can put it in quarantine).
        $exceptions->report(function (Throwable $exception): void {
            try {
                $failures = app(ModuleFailures::class);
                if (($module = $failures->moduleOf($exception)) !== null) {
                    $failures->report($module, $exception, 'request');
                }
            } catch (Throwable) {
                // Counting failures must never replace the failure itself.
            }
        });
        // read-api: one error shape for /api/v1, whatever raised the exception
        $exceptions->render(fn (Throwable $exception, Request $request) => $request->is('api/v1', 'api/v1/*') ? ApiV1Errors::render($exception) : null);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
