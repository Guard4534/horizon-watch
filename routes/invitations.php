<?php

use App\Http\Controllers\Auth\InvitationController;
use Illuminate\Support\Facades\Route;

Route::get('invitations/{code}', [InvitationController::class, 'show'])->name('invitations.show');

Route::middleware('throttle:'.config()->integer('horizon-watch.rate_limits.invitations_per_minute').',1')->group(function () {
    Route::post('invitations/{code}/register', [InvitationController::class, 'register'])
        ->middleware('guest')
        ->name('invitations.register');

    Route::post('invitations/{code}/accept', [InvitationController::class, 'accept'])
        ->middleware('auth')
        ->name('invitations.accept');

    Route::delete('invitations/{code}', [InvitationController::class, 'decline'])
        ->middleware('auth')
        ->name('invitations.decline');
});
