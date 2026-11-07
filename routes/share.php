<?php

use App\Http\Controllers\PublicShareController;
use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\ResolveShareLink;
use Illuminate\Support\Facades\Route;

// Public, read-only report links. No account is involved: the link itself is the credential.
Route::middleware(['web', EnsureInstalled::class, ResolveShareLink::class])->name('share.')->group(function (): void {
    Route::get('/s/{token}', [PublicShareController::class, 'show'])->name('show');
});
