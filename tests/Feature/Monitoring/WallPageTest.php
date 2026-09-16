<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Inertia\Testing\AssertableInertia as Assert;

test('filters in the query string do not change what the server sends', function () {
    $user = User::factory()->create();
    (new DatabaseSeeder)->seedMockupOrganization($user->currentTeam);

    $this->actingAs($user)
        ->get(route('wall', ['current_team' => $user->currentTeam->slug, 'filter' => 'problems', 'environment' => 'production', 'q' => 'shop']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('monitoring/Wall')->has('page.environments', 29));
});
