<?php

use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DemoController;
use App\Http\Controllers\FunnelController;
use App\Http\Controllers\GuideController;
use App\Http\Controllers\InstallationController;
use App\Http\Controllers\InstanceHealthController;
use App\Http\Controllers\ModuleAssetController;
use App\Http\Controllers\ModuleController;
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
        Route::get('/modules/{module}/assets/{file}', ModuleAssetController::class)->where('file', '[A-Za-z0-9_.-]+')->name('modules.assets');
        Route::get('/health', [InstanceHealthController::class, 'show'])->name('health');
        Route::put('/health', [InstanceHealthController::class, 'update'])->name('health.update');
        Route::get('/api/instance/health', [InstanceHealthController::class, 'api'])->name('health.api');
        Route::post('/health/geolocation', [InstanceHealthController::class, 'importGeolocation'])->name('health.geolocation');
        Route::get('/api/sites/{website}/filter-options', [AnalyticsController::class, 'filterOptions'])->name('sites.filter-options');
        Route::get('/api/sites/{website}/events/recent', [AnalyticsController::class, 'api'])->defaults('report', 'recent')->name('sites.api.recent');
        Route::get('/api/sites/{website}/{report}', [AnalyticsController::class, 'api'])->whereIn('report', ['overview', 'timeseries', 'dimensions', 'recent'])->name('sites.api');
        Route::get('/sites/{website}/funnels', [FunnelController::class, 'index'])->name('sites.funnels.index');
        Route::post('/sites/{website}/funnels', [FunnelController::class, 'store'])->name('sites.funnels.store');
        Route::post('/sites/{website}/funnels/{funnel}/calculate', [FunnelController::class, 'calculate'])->middleware('throttle:30,1')->name('sites.funnels.calculate');
        Route::post('/sites/{website}/journeys/calculate', [FunnelController::class, 'calculate'])->middleware('throttle:30,1')->name('sites.journeys.calculate');
        Route::get('/sites/{website}/funnels/{funnel}', [FunnelController::class, 'show'])->name('sites.funnels.show');
        Route::put('/sites/{website}/funnels/{funnel}', [FunnelController::class, 'update'])->name('sites.funnels.update');
        Route::delete('/sites/{website}/funnels/{funnel}', [FunnelController::class, 'archive'])->name('sites.funnels.archive');
        Route::get('/sites/{website}/journeys', [FunnelController::class, 'journeys'])->middleware('throttle:30,1')->name('sites.journeys');
        Route::get('/api/sites/{website}/journey-reports/{report}', [FunnelController::class, 'status'])->name('sites.journeys.status');
        Route::get('/sites/{website}/journey-reports/{report}/export', [FunnelController::class, 'export'])->name('sites.journeys.export');
        Route::get('/sites/{website}/export', [AnalyticsController::class, 'export'])->name('sites.export');
        Route::get('/settings', [WorkspaceController::class, 'settings'])->name('settings');
        Route::get('/settings/modules', [ModuleController::class, 'index'])->name('modules.index');
        Route::post('/settings/modules/install', [ModuleController::class, 'install'])->name('modules.install');
        Route::get('/settings/modules/{module}', [ModuleController::class, 'settings'])->name('modules.settings');
        Route::post('/settings/modules/{module}/scope', [ModuleController::class, 'updateScope'])->name('modules.scope');
        Route::post('/settings/modules/{module}/enable', [ModuleController::class, 'enable'])->name('modules.enable');
        Route::post('/settings/modules/{module}/disable', [ModuleController::class, 'disable'])->name('modules.disable');
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
