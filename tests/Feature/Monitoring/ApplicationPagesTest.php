<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->slug = $this->user->currentTeam->slug;
});

test('the application list groups environments under each application', function () {
    $this->actingAs($this->user)
        ->get(route('applications.index', ['current_team' => $this->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('page.groups', 9)
            ->where('page.environmentCount', 29)
            ->where('page.groups.0.application.id', 'fatturaomatic')
            ->has('page.groups.0.environments', 4)
            ->where('page.groups.0.triageCount', fn (int $count) => $count >= 1));
});

test('an application page shows one card per environment with a sparkline', function () {
    $this->actingAs($this->user)
        ->get(route('applications.show', ['current_team' => $this->slug, 'application' => 'fatturaomatic']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.application.name', 'Fatturaomatic')
            ->has('page.cards', 4)
            ->has('page.cards.0.sparkline', 24)
            ->where('page.recentAlerts', fn ($alerts) => count($alerts) <= 3));
});
