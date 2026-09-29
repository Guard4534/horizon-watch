<?php

use App\Http\Controllers\Settings\AlertEmailsController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Teams\TeamController;
use App\Http\Controllers\Teams\TeamInvitationController;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::patch('settings/alert-emails', AlertEmailsController::class)->name('alert-emails.update');
});

Route::middleware(['auth'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:'.config()->integer('horizon-watch.rate_limits.password_update_per_minute').',1')
        ->name('user-password.update');

    Route::get('settings/teams', [TeamController::class, 'index'])->name('teams.index');
    Route::post('settings/teams', [TeamController::class, 'store'])->name('teams.store');

    Route::middleware(EnsureTeamMembership::class)->group(function () {
        Route::patch('settings/teams/{team}', [TeamController::class, 'update'])->name('teams.update');
        Route::delete('settings/teams/{team}', [TeamController::class, 'destroy'])->name('teams.destroy');
        Route::post('settings/teams/{team}/switch', [TeamController::class, 'switch'])->name('teams.switch');
        Route::delete('settings/teams/{team}/leave', [TeamController::class, 'leave'])->name('teams.leave');
    });
});

Route::prefix('{current_team}')
    ->middleware(['auth', EnsureTeamMembership::class.':admin'])
    ->group(function () {
        Route::post('members/invitations', [TeamInvitationController::class, 'store'])->name('members.invitations.store');

        Route::post('members/invitations/{invitation:id}/resend', [TeamInvitationController::class, 'resend'])
            ->middleware('throttle:'.config()->integer('horizon-watch.rate_limits.invitations_per_minute').',1')
            ->name('members.invitations.resend');

        Route::delete('members/invitations/{invitation:id}', [TeamInvitationController::class, 'destroy'])->name('members.invitations.destroy');
    });
