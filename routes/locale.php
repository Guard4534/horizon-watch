<?php

use App\Http\Controllers\Settings\LocaleController;
use Illuminate\Support\Facades\Route;

Route::patch('locale', LocaleController::class)
    ->middleware('throttle:30,1')
    ->name('locale.update');
