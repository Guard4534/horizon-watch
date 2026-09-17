<?php

use App\Enums\MemberVisibility;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('alert emails are off by default and shown on the profile and the me page', function () {
    $user = User::factory()->create();

    expect($user->fresh()->alert_emails)->toBeFalse();

    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('alertEmails', false));

    $this->get(route('me', ['current_team' => $user->currentTeam->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.alertEmails', false)
            ->where('page.memberCount', 1));
});

test('a person turns alert emails on and off for every organization', function () {
    $user = User::factory()->create();
    $other = Team::factory()->create();
    $other->members()->attach($user, ['role' => TeamRole::Viewer->value, 'visibility' => MemberVisibility::All->value]);

    $this->actingAs($user)
        ->patch(route('alert-emails.update'), ['alertEmails' => true])
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Alert emails turned on.');

    expect($user->fresh()->alert_emails)->toBeTrue();

    $this->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('alertEmails', true));
    $this->get(route('me', ['current_team' => $other->slug]))
        ->assertInertia(fn (Assert $page) => $page->where('page.alertEmails', true));

    $this->patch(route('alert-emails.update'), ['alertEmails' => false])
        ->assertInertiaFlash('toast.message', 'Alert emails turned off.');

    expect($user->fresh()->alert_emails)->toBeFalse();
});

test('the preference touches only the person who changes it', function () {
    $user = User::factory()->create();
    $someoneElse = User::factory()->create();

    $this->actingAs($user)->patch(route('alert-emails.update'), ['alertEmails' => true]);

    expect($someoneElse->fresh()->alert_emails)->toBeFalse();
});

test('the preference must be a boolean', function (mixed $value) {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('alert-emails.update'), ['alertEmails' => $value])
        ->assertSessionHasErrors('alertEmails');

    expect($user->fresh()->alert_emails)->toBeFalse();
})->with(['yes', null, [[]]]);

test('a guest cannot change the preference', function () {
    User::factory()->create();

    $this->patch(route('alert-emails.update'), ['alertEmails' => true])
        ->assertRedirect(route('login'));
});

test('the preference is independent of the role', function (TeamRole $role) {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value, 'visibility' => MemberVisibility::All->value]);
    $user->switchTeam($team);

    $this->actingAs($user)
        ->patch(route('alert-emails.update'), ['alertEmails' => true])
        ->assertSessionHasNoErrors();

    expect($user->fresh()->alert_emails)->toBeTrue();
})->with([TeamRole::Viewer, TeamRole::Member]);
