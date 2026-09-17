<?php

use App\Http\Controllers\Monitoring\AlertActionController;
use App\Http\Controllers\Monitoring\AlertController;
use App\Http\Controllers\Monitoring\AlertRuleController;
use App\Http\Controllers\Monitoring\AlertSettingsController;
use App\Http\Controllers\Monitoring\ApplicationController;
use App\Http\Controllers\Monitoring\ConnectionTestController;
use App\Http\Controllers\Monitoring\EnvironmentController;
use App\Http\Controllers\Monitoring\MeController;
use App\Http\Controllers\Monitoring\MemberController;
use App\Http\Controllers\Monitoring\WallController;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Support\Facades\Route;

Route::get('wall', WallController::class)->name('wall');

Route::get('me', MeController::class)->name('me');

Route::get('applications', [ApplicationController::class, 'index'])->name('applications.index');

Route::get('applications/create', [ApplicationController::class, 'create'])
    ->middleware(EnsureTeamMembership::class.':admin')
    ->name('applications.create');
Route::post('applications', [ApplicationController::class, 'store'])
    ->middleware(EnsureTeamMembership::class.':admin')
    ->name('applications.store');

Route::post('applications/test-connection', [ConnectionTestController::class, 'application'])
    ->middleware('throttle:test-connection')
    ->name('applications.test-connection');

Route::get('applications/{application}', [ApplicationController::class, 'show'])->name('applications.show');

Route::get('applications/{application}/edit', [ApplicationController::class, 'edit'])
    ->middleware(EnsureTeamMembership::class.':admin')
    ->scopeBindings()
    ->name('applications.edit');
Route::patch('applications/{application}', [ApplicationController::class, 'update'])
    ->middleware(EnsureTeamMembership::class.':admin')
    ->scopeBindings()
    ->name('applications.update');
Route::delete('applications/{application}', [ApplicationController::class, 'destroy'])
    ->middleware(EnsureTeamMembership::class.':admin')
    ->scopeBindings()
    ->name('applications.destroy');

Route::get('applications/{application}/environments/create', [EnvironmentController::class, 'create'])
    ->middleware(EnsureTeamMembership::class.':admin')
    ->scopeBindings()
    ->name('environments.create');
Route::post('applications/{application}/environments', [EnvironmentController::class, 'store'])
    ->middleware(EnsureTeamMembership::class.':admin')
    ->scopeBindings()
    ->name('environments.store');

Route::get('environments/{environment}', [EnvironmentController::class, 'show'])->name('environments.show');

Route::post('environments/{environment}/test-connection', [ConnectionTestController::class, 'environment'])
    ->middleware('throttle:test-connection')
    ->scopeBindings()
    ->name('environments.test-connection');

Route::get('environments/{environment}/edit', [EnvironmentController::class, 'edit'])
    ->middleware(EnsureTeamMembership::class.':admin')
    ->scopeBindings()
    ->name('environments.edit');
Route::patch('environments/{environment}', [EnvironmentController::class, 'update'])
    ->middleware(EnsureTeamMembership::class.':admin')
    ->scopeBindings()
    ->name('environments.update');
Route::delete('environments/{environment}', [EnvironmentController::class, 'destroy'])
    ->middleware(EnsureTeamMembership::class.':admin')
    ->scopeBindings()
    ->name('environments.destroy');

Route::get('members', [MemberController::class, 'index'])->name('members.index');
Route::patch('members/{user}', [MemberController::class, 'update'])
    ->middleware(EnsureTeamMembership::class.':admin')
    ->name('members.update');
Route::delete('members/{user}', [MemberController::class, 'destroy'])
    ->middleware(EnsureTeamMembership::class.':admin')
    ->name('members.destroy');

Route::get('alerts', [AlertController::class, 'index'])->name('alerts.index');
Route::get('alert-rules/{scope?}', [AlertRuleController::class, 'index'])->name('alert-rules.index');

Route::middleware('can:manageAlertRules,current_team')->group(function () {
    Route::put('alert-rules/{scope}', [AlertRuleController::class, 'update'])->name('alert-rules.update');
    Route::delete('alert-rules/{scope}', [AlertRuleController::class, 'reset'])->name('alert-rules.reset');

    Route::put('alert-settings', [AlertSettingsController::class, 'update'])->name('alert-settings.update');
    Route::post('alert-settings/webhook-secret', [AlertSettingsController::class, 'regenerateSecret'])
        ->name('alert-settings.regenerate-secret');
    Route::post('alert-settings/test', [AlertSettingsController::class, 'test'])
        ->middleware('throttle:test-notification')
        ->name('alert-settings.test');
});

Route::post('alerts/{alert}/mute', [AlertActionController::class, 'mute'])->name('alerts.mute');
Route::delete('alerts/{alert}/mute', [AlertActionController::class, 'unmute'])->name('alerts.unmute');
Route::post('alerts/{alert}/handle', [AlertActionController::class, 'handle'])->name('alerts.handle');
