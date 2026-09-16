<?php

use App\Http\Controllers\HomeController;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

Route::prefix('{current_team}')
    ->middleware(['auth', EnsureTeamMembership::class])
    ->group(__DIR__.'/monitoring.php');

require __DIR__.'/setup.php';
require __DIR__.'/locale.php';
require __DIR__.'/settings.php';
require __DIR__.'/invitations.php';
