<?php

use App\Http\Controllers\Auth\SetupController;
use App\Http\Middleware\EnsureSetupIsPending;
use Illuminate\Support\Facades\Route;

Route::middleware(['guest', EnsureSetupIsPending::class, 'throttle:10,1'])->group(function () {
    Route::get('setup', [SetupController::class, 'create'])->name('setup.create');
    Route::post('setup', [SetupController::class, 'store'])->name('setup.store');
});
