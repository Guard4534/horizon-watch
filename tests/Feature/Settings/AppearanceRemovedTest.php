<?php

use App\Models\User;

test('there is no appearance setting: the panel only has a dark theme', function () {
    $this->actingAs(User::factory()->create())
        ->get('/settings/appearance')
        ->assertNotFound();
});
