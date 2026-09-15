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
