<?php

use App\Http\Controllers\Monitoring\AlertController;
use App\Http\Controllers\Monitoring\AlertRuleController;
use App\Http\Controllers\Monitoring\ApplicationController;
use App\Http\Controllers\Monitoring\EnvironmentController;
use App\Http\Controllers\Monitoring\WallController;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Support\Facades\Route;

Route::get('wall', WallController::class)->name('wall');

Route::get('applications', [ApplicationController::class, 'index'])->name('applications.index');

// Registered before the {application} wildcard below on purpose: as a
// literal segment at the same depth, "create" would otherwise be swallowed
// by applications.show and resolved as an (unknown) application slug.
Route::get('applications/create', [ApplicationController::class, 'create'])
    ->middleware(EnsureTeamMembership::class.':admin')
    ->name('applications.create');
Route::post('applications', [ApplicationController::class, 'store'])
    ->middleware(EnsureTeamMembership::class.':admin')
    ->name('applications.store');

Route::get('applications/{application}', [ApplicationController::class, 'show'])->name('applications.show');

Route::get('applications/{application}/edit', [ApplicationController::class, 'edit'])
    ->middleware(EnsureTeamMembership::class.':admin')
    ->name('applications.edit');
Route::patch('applications/{application}', [ApplicationController::class, 'update'])
    ->middleware(EnsureTeamMembership::class.':admin')
    ->name('applications.update');
Route::delete('applications/{application}', [ApplicationController::class, 'destroy'])
    ->middleware(EnsureTeamMembership::class.':admin')
    ->name('applications.destroy');

Route::get('applications/{application}/environments/create', [EnvironmentController::class, 'create'])
    ->middleware(EnsureTeamMembership::class.':admin')
    ->name('environments.create');
Route::post('applications/{application}/environments', [EnvironmentController::class, 'store'])
    ->middleware(EnsureTeamMembership::class.':admin')
    ->name('environments.store');

Route::get('environments/{environment}', [EnvironmentController::class, 'show'])->name('environments.show');

Route::get('environments/{environment}/edit', [EnvironmentController::class, 'edit'])
    ->middleware(EnsureTeamMembership::class.':admin')
    ->name('environments.edit');
Route::patch('environments/{environment}', [EnvironmentController::class, 'update'])
    ->middleware(EnsureTeamMembership::class.':admin')
    ->name('environments.update');
Route::delete('environments/{environment}', [EnvironmentController::class, 'destroy'])
    ->middleware(EnsureTeamMembership::class.':admin')
    ->name('environments.destroy');

Route::get('alerts', [AlertController::class, 'index'])->name('alerts.index');
Route::get('alert-rules/{scope?}', [AlertRuleController::class, 'index'])->name('alert-rules.index');
