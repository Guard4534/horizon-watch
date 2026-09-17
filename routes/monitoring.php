<?php

use App\Http\Controllers\Monitoring\AlertController;
use App\Http\Controllers\Monitoring\AlertRuleController;
use App\Http\Controllers\Monitoring\ApplicationController;
use App\Http\Controllers\Monitoring\ConnectionTestController;
use App\Http\Controllers\Monitoring\EnvironmentController;
use App\Http\Controllers\Monitoring\MemberController;
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

// Tests an address that is not saved yet (the wizard, the add-environment
// form); the controller asks for the permission that creates applications.
Route::post('applications/test-connection', [ConnectionTestController::class, 'application'])
    ->middleware('throttle:test-connection')
    ->name('applications.test-connection');

Route::get('applications/{application}', [ApplicationController::class, 'show'])->name('applications.show');

// scopeBindings(): {application} and {environment} slugs are only unique
// per organization, not globally, so without it Laravel's default implicit
// binding could resolve another organization's row of the same slug (and
// the controller would then 404 on a resource its own admin legitimately
// owns). Scoping makes {current_team} the parent of the query itself
// (Team::applications() / Team::environments()), so a slug that only
// exists in another organization simply doesn't resolve at all — same 404,
// but for the right reason, and it never leaks whether the slug exists
// elsewhere.
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

// No admin middleware: without a body this is the member's "test
// connection" on a watched environment, with one it is the edit form's
// test and the controller asks for the update permission instead.
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

// The Members view replaces "settings/teams/{team}" in the sidebar. Reading
// it is open to every member (the actions inside are not); changing a role
// or a visibility and removing someone need admin, and the TeamPolicy
// decides again per target.
//
// "members/invitations/…" (sending, resending and revoking) lives in
// routes/settings.php next to the rest of the invitation flow.
Route::get('members', [MemberController::class, 'index'])->name('members.index');
Route::patch('members/{user}', [MemberController::class, 'update'])
    ->middleware(EnsureTeamMembership::class.':admin')
    ->name('members.update');
Route::delete('members/{user}', [MemberController::class, 'destroy'])
    ->middleware(EnsureTeamMembership::class.':admin')
    ->name('members.destroy');

Route::get('alerts', [AlertController::class, 'index'])->name('alerts.index');
Route::get('alert-rules/{scope?}', [AlertRuleController::class, 'index'])->name('alert-rules.index');
