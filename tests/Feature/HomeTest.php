<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are sent to the login page', function () {
    $this->get('/')->assertRedirect(route('login'));
});

test('members are sent to the wall of their current organization', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/')
        ->assertRedirect(route('wall', ['current_team' => $user->currentTeam->slug]));
});

test('people without an organization are sent to the organizations page', function () {
    $user = User::factory()->withoutOrganization()->create();

    $this->actingAs($user)
        ->get('/')
        ->assertRedirect(route('teams.index'));

    $this->actingAs($user)
        ->get(route('teams.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('teams/Index')
            ->where('teams', [])
            ->where('currentTeam', null));
});

test('a member whose current organization is gone is moved to one they still belong to', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $user->update(['current_team_id' => null]);

    $this->actingAs($user->fresh())
        ->get('/')
        ->assertRedirect(route('wall', ['current_team' => $team->slug]));

    expect($user->fresh()->current_team_id)->toBe($team->id);
});
