<?php

use App\Http\Controllers\Api\V1\FunnelController;
use App\Http\Controllers\Api\V1\GoalController;
use App\Http\Controllers\Api\V1\OpenApiController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\SiteController;
use App\Http\Middleware\ResolveApiToken;
use Illuminate\Support\Facades\Route;

// read-api: the read-only API. No session, no cookie, no CSRF: the bearer token is the credential, and it carries the site.
Route::prefix('api/v1')->name('api.v1.')->group(function (): void {
    Route::middleware(ResolveApiToken::class)->group(function (): void {
        Route::get('/site', SiteController::class)->name('site');
        Route::get('/overview', [ReportController::class, 'overview'])->name('overview');
        Route::get('/timeseries', [ReportController::class, 'timeseries'])->name('timeseries');
        Route::get('/dimensions/{dimension}', [ReportController::class, 'dimension'])->name('dimension');
        Route::get('/goals', [GoalController::class, 'index'])->name('goals');
        Route::get('/goals/{id}', [GoalController::class, 'show'])->name('goals.show');
        Route::get('/funnels', [FunnelController::class, 'index'])->name('funnels');
        Route::get('/funnels/{id}', [FunnelController::class, 'show'])->name('funnels.show');
    });
    // The description of the API needs no token.
    Route::get('/openapi.json', OpenApiController::class)->name('openapi');
});
