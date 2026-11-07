<?php

use App\Http\Controllers\Api\V1\SiteController;
use App\Http\Middleware\ResolveApiToken;
use Illuminate\Support\Facades\Route;

// read-api: the read-only API. No session, no cookie, no CSRF: the bearer token is the credential, and it carries the site.
Route::prefix('api/v1')->name('api.v1.')->group(function (): void {
    Route::middleware(ResolveApiToken::class)->group(function (): void {
        Route::get('/site', SiteController::class)->name('site');
    });
});
