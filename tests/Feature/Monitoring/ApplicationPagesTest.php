<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Readings;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->slug = $this->user->currentTeam->slug;
    (new DatabaseSeeder)->seedMockupOrganization($this->user->currentTeam);
    Readings::mockup($this->user->currentTeam);
});

test('the application list groups environments under each application', function () {
    $this->actingAs($this->user)
        ->get(route('applications.index', ['current_team' => $this->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('page.groups', 9)
            ->where('page.environmentCount', 29)
            ->where('page.groups.0.application.id', 'invoice-desk')
            ->has('page.groups.0.environments', 4)
            ->where('page.groups.0.triageCount', 1));
});

test('an application page shows one card per environment with its pending trend', function () {
    $this->actingAs($this->user)
        ->get(route('applications.show', ['current_team' => $this->slug, 'application' => 'invoice-desk']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.application.name', 'Invoice Desk')
            ->has('page.environments', 4)
            ->has('page.environments.0.trend', 12)
            ->has('page.recentAlerts', 1)
            ->where('page.recentAlerts.0.metric', 'horizon.master_inactive'));
});
