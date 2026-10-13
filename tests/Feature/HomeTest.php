<?php

use App\Models\User;

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
    $user = User::factory()->create();
    $user->update(['current_team_id' => null]);
    $user = $user->fresh();

    $this->actingAs($user)
        ->get('/')
        ->assertRedirect(route('teams.index'));

    $this->actingAs($user)
        ->get(route('teams.index'))
        ->assertOk();
});
