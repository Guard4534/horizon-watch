<?php

use App\Http\Controllers\Settings\LocaleController;
use Illuminate\Support\Facades\Route;

Route::patch('locale', LocaleController::class)
    ->middleware('throttle:'.config()->integer('horizon-watch.rate_limits.locale_per_minute').',1')
    ->name('locale.update');
