<?php

use App\Models\User;
use App\Monitoring\MonitoringRepository;
use Database\Seeders\DatabaseSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->slug = $this->user->currentTeam->slug;
    (new DatabaseSeeder)->seedMockupOrganization($this->user->currentTeam);
});

test('a down environment carries its incident', function () {
    $this->actingAs($this->user)
        ->get(route('environments.show', ['current_team' => $this->slug, 'environment' => 'fatturaomatic-production']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.environment.status', 'inactive')
            ->where('page.openAlert.metric', 'horizon.master_inactive')
            ->has('page.failedJobs', 5)
            ->has('page.longRunningJobs', 3)
            ->has('page.throughput', 48)
            ->has('page.maxWait', 48));
});

test('a healthy environment has no incident', function () {
    // The repository resolves the viewer from the authenticated guard, so
    // acting as the user has to happen before it is asked for anything.
    $this->actingAs($this->user);

    $healthy = collect(app(MonitoringRepository::class)->environments($this->user->currentTeam))
        ->first(fn ($environment) => $environment->status->isHealthy());

    $this->actingAs($this->user)
        ->get(route('environments.show', ['current_team' => $this->slug, 'environment' => $healthy->id]))
        ->assertInertia(fn (Assert $page) => $page->where('page.openAlert', null));
});
