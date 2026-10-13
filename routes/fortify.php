<?php

use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;
use Laravel\Fortify\Http\Controllers\ConfirmablePasswordController;
use Laravel\Fortify\Http\Controllers\PasswordResetLinkController;

Route::middleware(config()->array('fortify.middleware'))->group(function () {
    if (Features::enabled(Features::resetPasswords())) {
        Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
            ->middleware(['guest:'.config()->string('fortify.guard'), 'throttle:password-reset'])
            ->name('password.email');
    }

    Route::post('user/confirm-password', [ConfirmablePasswordController::class, 'store'])
        ->middleware(['auth:'.config()->string('fortify.guard'), 'throttle:current-password'])
        ->name('password.confirm.store');
});
