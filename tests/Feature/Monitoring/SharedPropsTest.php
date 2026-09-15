<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('pages share what the sidebar needs', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('wall', ['current_team' => $user->currentTeam->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('currentTeam.slug')
            ->has('teams')
            ->has('locale')
            ->where('openAlertCount', fn ($count) => is_int($count))
            ->has('auth.user.name'));
});

test('settings pages still render inside the new shell', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/settings/profile')->assertOk();
});
