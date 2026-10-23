<?php

use App\Enums\AlertRuleMetric;
use App\Enums\EnvironmentStatus;
use App\Enums\MemberVisibility;
use App\Enums\TeamRole;
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
            ->where('page.groups.0.attentionCount', 1)
            ->where('page.groups.1.application.id', 'acme-shop')
            ->has('page.groups.1.environments', 1)
            ->where('page.groups.1.attentionCount', 0));
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

test('an application is named after its production environment, or the first one the viewer sees', function () {
    $member = User::factory()->create();
    $this->user->currentTeam->members()->attach($member, [
        'role' => TeamRole::Member->value,
        'visibility' => MemberVisibility::NonProduction->value,
    ]);

    $this->actingAs($this->user)
        ->get(route('applications.index', ['current_team' => $this->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.groups.0.application.domain', 'production.example.com')
            ->missing('page.groups.0.application.host'));

    $this->actingAs($member)
        ->get(route('applications.index', ['current_team' => $this->slug]))
        ->assertInertia(fn (Assert $page) => $page->where('page.groups.0.application.domain', 'staging.example.com'));

    $this->actingAs($this->user)
        ->get(route('applications.show', ['current_team' => $this->slug, 'application' => 'invoice-desk']))
        ->assertInertia(fn (Assert $page) => $page->where('page.application.domain', 'production.example.com'));
});

test('the application list leaves a paused collection out of the attention count', function () {
    $shop = Application::query()->where('name', 'Acme Shop')->firstOrFail();
    $paused = Environment::factory()->for($shop)->staging()->create(['polling_enabled' => false]);
    Readings::record($paused, EnvironmentStatus::Inactive, [AlertRuleMetric::HorizonMasterInactive]);

    $this->actingAs($this->user)
        ->get(route('applications.index', ['current_team' => $this->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.groups.0.attentionCount', 1)
            ->where('page.groups.1.application.id', 'acme-shop')
            ->has('page.groups.1.environments', 2)
            ->where('page.groups.1.attentionCount', 0)
            ->missing('page.groups.1.triageCount'));
});

test('the worst status of an application page leaves a paused collection out', function () {
    $shop = Application::query()->where('name', 'Acme Shop')->firstOrFail();
    $paused = Environment::factory()->for($shop)->staging()->create(['polling_enabled' => false]);
    Readings::record($paused, EnvironmentStatus::Inactive, [AlertRuleMetric::HorizonMasterInactive]);

    $this->actingAs($this->user)
        ->get(route('applications.show', ['current_team' => $this->slug, 'application' => 'acme-shop']))
        ->assertInertia(fn (Assert $page) => $page->where('page.worstStatus', 'active'));
});

test('each application group carries the worst status among the environments that need attention', function () {
    $this->actingAs($this->user)
        ->get(route('applications.index', ['current_team' => $this->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.groups.0.application.id', 'invoice-desk')
            ->where('page.groups.0.worstStatus', 'inactive')
            ->where('page.groups.1.application.id', 'acme-shop')
            ->where('page.groups.1.worstStatus', null));
});

test('a paused collection does not set the worst status of its application', function () {
    $billing = Application::factory()->for($this->user->currentTeam)->create(['name' => 'Billing']);
    Readings::record(Environment::factory()->for($billing)->production()->create(), EnvironmentStatus::Degraded, [AlertRuleMetric::QueuePending]);
    Readings::record(Environment::factory()->for($billing)->staging()->create(), EnvironmentStatus::Paused, [AlertRuleMetric::HorizonPaused]);
    Readings::record(Environment::factory()->for($billing)->demo()->create(['polling_enabled' => false]), EnvironmentStatus::Inactive, [AlertRuleMetric::HorizonMasterInactive]);

    $this->actingAs($this->user)
        ->get(route('applications.index', ['current_team' => $this->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.groups', fn ($groups) => collect($groups)->firstWhere('application.id', 'billing')['worstStatus'] === 'paused'));
});
