<?php

use Illuminate\Support\Facades\Route;
use Perysee\Modules\Annotations\NoteController;

Route::get('/sites/{website}', [NoteController::class, 'index'])->name('site');
Route::post('/sites/{website}/notes', [NoteController::class, 'store'])->name('notes.store');
Route::delete('/sites/{website}/notes/{note}', [NoteController::class, 'destroy'])->whereNumber('note')->name('notes.destroy');
Route::post('/settings', [NoteController::class, 'settings'])->name('settings');
