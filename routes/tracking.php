<?php

use App\Http\Controllers\TrackingController;
use Illuminate\Support\Facades\Route;

Route::get('/lueur.js', [TrackingController::class, 'script'])->name('tracker.script');
Route::post('/api/collect', [TrackingController::class, 'collect'])->name('tracker.collect');
Route::options('/api/collect', [TrackingController::class, 'preflight'])->name('tracker.preflight');
