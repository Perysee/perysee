<?php

use App\Http\Middleware\EnsureInstalled;
use Illuminate\Support\Facades\Route;

// Public, read-only report links. No account is involved: the link itself is the credential.
Route::middleware(['web', EnsureInstalled::class])->name('share.')->group(function (): void {
    Route::get('/s/{token}', fn () => abort(404))->name('show');
});
