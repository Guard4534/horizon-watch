<?php

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('every role opens the profile page, with the organization member count', function (TeamRole $role) {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);
    $team->members()->attach(User::factory()->count(2)->create(), ['role' => TeamRole::Viewer->value]);
    $user->switchTeam($team);

    $expected = $team->members()->count();

    $this->actingAs($user)
        ->get(route('me', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('monitoring/Me')
            ->where('page', ['memberCount' => $expected, 'alertEmails' => false]));
})->with([
    'owner' => TeamRole::Owner,
    'admin' => TeamRole::Admin,
    'member' => TeamRole::Member,
    'viewer' => TeamRole::Viewer,
]);

test('another organization shows up only as its name and the viewer\'s role in it', function () {
    $user = User::factory()->create(['name' => 'Ada Viewer']);
    $current = $user->currentTeam;

    $other = Team::factory()->create(['name' => 'Northwind Ops']);
    $other->members()->attach($user, ['role' => TeamRole::Viewer->value]);
    $stranger = User::factory()->create(['name' => 'Grace Stranger', 'email' => 'grace@example.net']);
    $other->members()->attach($stranger, ['role' => TeamRole::Admin->value]);

    $response = $this->actingAs($user)
        ->get(route('me', ['current_team' => $current->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.memberCount', 1)
            ->where('teams', fn ($teams) => collect($teams)->contains(
                fn ($team) => $team['name'] === 'Northwind Ops' && $team['role'] === 'viewer',
            )));

    expect($response->getContent())
        ->not->toContain('grace@example.net')
        ->not->toContain('Grace Stranger');
});

test('someone outside the organization cannot open its profile page', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();

    $this->actingAs($user)
        ->get(route('me', ['current_team' => $team->slug]))
        ->assertForbidden();
});

test('a guest is sent to the login page', function () {
    $team = Team::factory()->create();
    User::factory()->create();

    $this->get(route('me', ['current_team' => $team->slug]))
        ->assertRedirect(route('login'));
});
