<?php

use App\Http\Controllers\Monitoring\AlertController;
use App\Http\Controllers\Monitoring\AlertRuleController;
use App\Http\Controllers\Monitoring\ApplicationController;
use App\Http\Controllers\Monitoring\EnvironmentController;
use App\Http\Controllers\Monitoring\WallController;
use Illuminate\Support\Facades\Route;

Route::get('wall', WallController::class)->name('wall');
Route::get('applications', [ApplicationController::class, 'index'])->name('applications.index');
Route::get('applications/{application}', [ApplicationController::class, 'show'])->name('applications.show');
Route::get('environments/{environment}', [EnvironmentController::class, 'show'])->name('environments.show');
Route::get('alerts', [AlertController::class, 'index'])->name('alerts.index');
Route::get('alert-rules/{scope?}', [AlertRuleController::class, 'index'])->name('alert-rules.index');
