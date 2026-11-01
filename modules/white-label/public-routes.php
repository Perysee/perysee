<?php

use Illuminate\Support\Facades\Route;
use Perysee\Modules\WhiteLabel\SettingsController;

Route::get('/logo', [SettingsController::class, 'logo'])->name('logo');
