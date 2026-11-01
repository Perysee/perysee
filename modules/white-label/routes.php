<?php

use Illuminate\Support\Facades\Route;
use Perysee\Modules\WhiteLabel\SettingsController;

Route::post('/settings', [SettingsController::class, 'save'])->name('save');
Route::delete('/settings', [SettingsController::class, 'reset'])->name('reset');
Route::post('/logo', [SettingsController::class, 'uploadLogo'])->name('logo.upload');
Route::delete('/logo', [SettingsController::class, 'removeLogo'])->name('logo.remove');
