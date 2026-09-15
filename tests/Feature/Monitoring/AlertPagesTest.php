<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->slug = $this->user->currentTeam->slug;
});

test('the email preview shows a critical alert', function () {
    $this->actingAs($this->user)
        ->get(route('alerts.index', ['current_team' => $this->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.preview.severity', 'critical')
            ->has('page.alerts.0.channels', 2));
});

test('a scope with overrides marks them', function () {
    $this->actingAs($this->user)
        ->get(route('alert-rules.index', ['current_team' => $this->slug, 'scope' => 'worker-batch']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.scope', 'worker-batch')
            ->where('page.rules', fn ($rules) => collect($rules)->where('origin', 'override')->count() === 2));
});
