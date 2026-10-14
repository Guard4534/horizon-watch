<?php

use App\Enums\AlertRuleMetric;
use App\Enums\EnvironmentStatus;
use App\Models\Application;
use App\Models\Environment;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Readings;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->slug = $this->user->currentTeam->slug;

    $invoices = Application::factory()->for($this->user->currentTeam)->create(['name' => 'Invoice Desk']);
    $shop = Application::factory()->for($this->user->currentTeam)->create(['name' => 'Acme Shop']);

    Readings::record(Environment::factory()->for($invoices)->production()->create(), EnvironmentStatus::Inactive, [AlertRuleMetric::HorizonMasterInactive]);
    Readings::record(Environment::factory()->for($invoices)->staging()->create());
    Readings::record(Environment::factory()->for($invoices)->demo()->create());
    Readings::record(Environment::factory()->for($shop)->production()->create());
});

test('the application list groups environments under each application', function () {
    $this->actingAs($this->user)
        ->get(route('applications.index', ['current_team' => $this->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('page.groups', 2)
            ->where('page.environmentCount', 4)
            ->where('page.groups.0.application.id', 'invoice-desk')
            ->has('page.groups.0.environments', 3)
            ->where('page.groups.0.triageCount', 1)
            ->where('page.groups.1.application.id', 'acme-shop')
            ->has('page.groups.1.environments', 1)
            ->where('page.groups.1.triageCount', 0));
});

test('an application page shows one card per environment with its pending trend', function () {
    $this->actingAs($this->user)
        ->get(route('applications.show', ['current_team' => $this->slug, 'application' => 'invoice-desk']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.application.name', 'Invoice Desk')
            ->has('page.environments', 3)
            ->has('page.environments.0.trend', 12)
            ->has('page.recentAlerts', 1)
            ->where('page.recentAlerts.0.metric', 'horizon.master_inactive'));
});
