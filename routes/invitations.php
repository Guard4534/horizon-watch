<?php

use App\Http\Controllers\Auth\InvitationController;
use Illuminate\Support\Facades\Route;

// Outside the "{current_team}" organization group on purpose: a guest
// following this link belongs to no organization yet, and an authenticated
// user may be looking at an invitation to a team that isn't their current
// one.
Route::get('invitations/{code}', [InvitationController::class, 'show'])->name('invitations.show');

Route::middleware('throttle:6,1')->group(function () {
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
