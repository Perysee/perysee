<?php

use App\Http\Middleware\BootstrapInstallation;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: fn () => require base_path('routes/tracking.php'),
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(BootstrapInstallation::class);
        $middleware->trimStrings(except: ['db_password']);
        $middleware->authenticateSessions();
        $middleware->redirectUsersTo('/sites');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['current_password', 'password', 'password_confirmation', 'db_password', 'installation_secret']);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
