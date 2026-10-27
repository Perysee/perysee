<?php

use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DemoController;
use App\Http\Controllers\GuideController;
use App\Http\Controllers\InstallationController;
use App\Http\Controllers\InstanceHealthController;
use App\Http\Controllers\SiteFaviconController;
use App\Http\Controllers\WebsiteController;
use App\Http\Controllers\WorkspaceController;
use App\Http\Middleware\EnsureInstalled;
use App\Installation\InstallationState;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route(! app(InstallationState::class)->isLocked() ? 'install' : (auth()->check() ? 'sites.index' : 'login')))->name('home');
Route::get('/install', [InstallationController::class, 'show'])->name('install');
Route::post('/install/unlock', [InstallationController::class, 'unlock'])->name('install.unlock');
Route::post('/install/database', [InstallationController::class, 'database'])->name('install.database');
Route::post('/install/administrator', [InstallationController::class, 'administrator'])->name('install.administrator');
Route::middleware(EnsureInstalled::class)->group(function (): void {
    Route::middleware('guest')->group(function (): void {
        Route::get('/login', [AuthController::class, 'create'])->name('login');
        Route::post('/login', [AuthController::class, 'store'])->name('login.store');
    });
    Route::middleware('auth')->group(function (): void {
        Route::get('/dashboard', [WorkspaceController::class, 'dashboard'])->name('dashboard');
        Route::get('/pixels', [WorkspaceController::class, 'pixels'])->name('pixels');
        Route::get('/health', [InstanceHealthController::class, 'show'])->name('health');
        Route::put('/health', [InstanceHealthController::class, 'update'])->name('health.update');
        Route::get('/api/instance/health', [InstanceHealthController::class, 'api'])->name('health.api');
        Route::post('/health/geolocation', [InstanceHealthController::class, 'importGeolocation'])->name('health.geolocation');
        Route::get('/api/sites/{website}/filter-options', [AnalyticsController::class, 'filterOptions'])->name('sites.filter-options');
        Route::get('/api/sites/{website}/events/recent', [AnalyticsController::class, 'api'])->defaults('report', 'recent')->name('sites.api.recent');
        Route::get('/api/sites/{website}/{report}', [AnalyticsController::class, 'api'])->whereIn('report', ['overview', 'timeseries', 'dimensions', 'recent'])->name('sites.api');
        Route::get('/sites/{website}/export', [AnalyticsController::class, 'export'])->name('sites.export');
        Route::get('/settings', [WorkspaceController::class, 'settings'])->name('settings');
        Route::get('/sites/{website}/favicon', SiteFaviconController::class)->middleware('throttle:120,1')->name('sites.favicon');
        Route::get('/sites/{website}/tracking', [WebsiteController::class, 'tracking'])->name('sites.tracking');
        Route::get('/sites/{website}/{section}', [WorkspaceController::class, 'sitePage'])->whereIn('section', ['countries', 'pages', 'events', 'sessions', 'realtime', 'sources', 'campaigns', 'devices', 'pixels'])->name('sites.section');
        Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
        Route::get('/account/password', [AuthController::class, 'editPassword'])->name('password.edit');
        Route::put('/account/password', [AuthController::class, 'updatePassword'])->name('password.update');
        Route::get('/guides/{guide}', [GuideController::class, 'show'])->name('guides.show');
        Route::get('/demo/{website}', [DemoController::class, 'show'])->name('demo.show');
        Route::resource('sites', WebsiteController::class)->parameters(['sites' => 'website']);
    });
});
