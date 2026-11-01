<?php

use Illuminate\Support\Facades\Route;
use Perysee\Modules\Example\GreetingController;

// Names get the module id as a prefix: this route is called "example.greeting".
Route::post('/greeting', [GreetingController::class, 'update'])->name('greeting');
