<?php

use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    $this->team = Team::factory()->create();

    $this->owner = User::factory()->create();
    $this->admin = User::factory()->create();
    $this->member = User::factory()->create();
    $this->viewer = User::factory()->create();

    $this->team->members()->attach($this->owner, ['role' => TeamRole::Owner->value]);
    $this->team->members()->attach($this->admin, ['role' => TeamRole::Admin->value]);
    $this->team->members()->attach($this->member, ['role' => TeamRole::Member->value]);
    $this->team->members()->attach($this->viewer, ['role' => TeamRole::Viewer->value]);

    $this->application = Application::factory()->for($this->team)->create();
    $this->environment = Environment::factory()->for($this->application)->create();
});

test('every role can see the wall, applications and environment details', function () {
    expect(Gate::forUser($this->owner)->allows('viewAny', [Application::class, $this->team]))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('viewAny', [Application::class, $this->team]))->toBeTrue()
        ->and(Gate::forUser($this->member)->allows('viewAny', [Application::class, $this->team]))->toBeTrue()
        ->and(Gate::forUser($this->viewer)->allows('viewAny', [Application::class, $this->team]))->toBeTrue();

    expect(Gate::forUser($this->owner)->allows('view', $this->environment))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('view', $this->environment))->toBeTrue()
        ->and(Gate::forUser($this->member)->allows('view', $this->environment))->toBeTrue()
        ->and(Gate::forUser($this->viewer)->allows('view', $this->environment))->toBeTrue();
});

test('owner, admin and member can mute an alert, viewer cannot', function () {
    expect(Gate::forUser($this->owner)->allows('muteAlert', $this->team))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('muteAlert', $this->team))->toBeTrue()
        ->and(Gate::forUser($this->member)->allows('muteAlert', $this->team))->toBeTrue()
        ->and(Gate::forUser($this->viewer)->allows('muteAlert', $this->team))->toBeFalse();
});

test('owner, admin and member can mark an anomaly as handled, viewer cannot', function () {
    expect(Gate::forUser($this->owner)->allows('handleAnomaly', $this->team))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('handleAnomaly', $this->team))->toBeTrue()
        ->and(Gate::forUser($this->member)->allows('handleAnomaly', $this->team))->toBeTrue()
        ->and(Gate::forUser($this->viewer)->allows('handleAnomaly', $this->team))->toBeFalse();
});

test('owner, admin and member can test the connection, viewer cannot', function () {
    expect(Gate::forUser($this->owner)->allows('testConnection', $this->environment))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('testConnection', $this->environment))->toBeTrue()
        ->and(Gate::forUser($this->member)->allows('testConnection', $this->environment))->toBeTrue()
        ->and(Gate::forUser($this->viewer)->allows('testConnection', $this->environment))->toBeFalse();
});

test('only owner and admin can create and update applications', function () {
    expect(Gate::forUser($this->owner)->allows('create', [Application::class, $this->team]))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('create', [Application::class, $this->team]))->toBeTrue()
        ->and(Gate::forUser($this->member)->allows('create', [Application::class, $this->team]))->toBeFalse()
        ->and(Gate::forUser($this->viewer)->allows('create', [Application::class, $this->team]))->toBeFalse();

    expect(Gate::forUser($this->owner)->allows('update', $this->application))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('update', $this->application))->toBeTrue()
        ->and(Gate::forUser($this->member)->allows('update', $this->application))->toBeFalse()
        ->and(Gate::forUser($this->viewer)->allows('update', $this->application))->toBeFalse();
});

test('only owner and admin can create and update environments', function () {
    expect(Gate::forUser($this->owner)->allows('create', [Environment::class, $this->application]))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('create', [Environment::class, $this->application]))->toBeTrue()
        ->and(Gate::forUser($this->member)->allows('create', [Environment::class, $this->application]))->toBeFalse()
        ->and(Gate::forUser($this->viewer)->allows('create', [Environment::class, $this->application]))->toBeFalse();

    expect(Gate::forUser($this->owner)->allows('update', $this->environment))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('update', $this->environment))->toBeTrue()
        ->and(Gate::forUser($this->member)->allows('update', $this->environment))->toBeFalse()
        ->and(Gate::forUser($this->viewer)->allows('update', $this->environment))->toBeFalse();
});

test('only owner and admin can delete applications and environments', function () {
    expect(Gate::forUser($this->owner)->allows('delete', $this->application))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('delete', $this->application))->toBeTrue()
        ->and(Gate::forUser($this->member)->allows('delete', $this->application))->toBeFalse()
        ->and(Gate::forUser($this->viewer)->allows('delete', $this->application))->toBeFalse();

    expect(Gate::forUser($this->owner)->allows('delete', $this->environment))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('delete', $this->environment))->toBeTrue()
        ->and(Gate::forUser($this->member)->allows('delete', $this->environment))->toBeFalse()
        ->and(Gate::forUser($this->viewer)->allows('delete', $this->environment))->toBeFalse();
});

test('only owner and admin can manage credentials', function () {
    expect(Gate::forUser($this->owner)->allows('manageCredentials', $this->environment))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('manageCredentials', $this->environment))->toBeTrue()
        ->and(Gate::forUser($this->member)->allows('manageCredentials', $this->environment))->toBeFalse()
        ->and(Gate::forUser($this->viewer)->allows('manageCredentials', $this->environment))->toBeFalse();
});

test('only owner and admin can manage alert rules', function () {
    expect(Gate::forUser($this->owner)->allows('manageAlertRules', $this->team))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('manageAlertRules', $this->team))->toBeTrue()
        ->and(Gate::forUser($this->member)->allows('manageAlertRules', $this->team))->toBeFalse()
        ->and(Gate::forUser($this->viewer)->allows('manageAlertRules', $this->team))->toBeFalse();
});

test('only owner and admin can invite and remove members', function () {
    expect(Gate::forUser($this->owner)->allows('inviteMember', $this->team))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('inviteMember', $this->team))->toBeTrue()
        ->and(Gate::forUser($this->member)->allows('inviteMember', $this->team))->toBeFalse()
        ->and(Gate::forUser($this->viewer)->allows('inviteMember', $this->team))->toBeFalse();

    expect(Gate::forUser($this->owner)->allows('removeMember', $this->team))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('removeMember', $this->team))->toBeTrue()
        ->and(Gate::forUser($this->member)->allows('removeMember', $this->team))->toBeFalse()
        ->and(Gate::forUser($this->viewer)->allows('removeMember', $this->team))->toBeFalse();
});

test('only the owner can delete the organization', function () {
    expect(Gate::forUser($this->owner)->allows('delete', $this->team))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('delete', $this->team))->toBeFalse()
        ->and(Gate::forUser($this->member)->allows('delete', $this->team))->toBeFalse()
        ->and(Gate::forUser($this->viewer)->allows('delete', $this->team))->toBeFalse();
});

test('a user outside the organization has no permission at all', function () {
    $stranger = User::factory()->create();

    expect(Gate::forUser($stranger)->allows('view', $this->environment))->toBeFalse()
        ->and(Gate::forUser($stranger)->allows('viewAny', [Application::class, $this->team]))->toBeFalse()
        ->and(Gate::forUser($stranger)->allows('muteAlert', $this->team))->toBeFalse();
});
