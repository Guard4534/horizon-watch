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

test('the worst anomaly leads the log, with its channels', function () {
    $this->actingAs($this->user)
        ->get(route('alerts.index', ['current_team' => $this->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.alerts.0.severity', 'critical')
            ->has('page.alerts.0.channels', 2));
});

test('a scope with overrides marks them', function () {
    $this->actingAs($this->user)
        ->get(route('alert-rules.index', ['current_team' => $this->slug, 'scope' => 'worker-batch']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.scope', 'worker-batch')
            ->where('page.rules', fn ($rules) => collect($rules)->where('origin', 'override')->count() === 2));
});
