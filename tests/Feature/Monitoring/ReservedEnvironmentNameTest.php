<?php

use App\Enums\EnvironmentColor;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Team;
use App\Models\User;

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->admin = User::factory()->create();
    $this->team->members()->attach($this->admin, ['role' => TeamRole::Admin->value]);
    $this->admin->switchTeam($this->team);
    $this->application = Application::factory()->for($this->team)->create();

    $this->environment = fn (string $name): array => [
        'name' => $name,
        'color' => EnvironmentColor::Prod->value,
        'horizonUrl' => 'https://reserved.example.com/horizon',
        'pollIntervalSeconds' => 15,
    ];
});

test('an environment cannot be created with the name of the organization rules scope', function () {
    $this->actingAs($this->admin)
        ->post(
            route('environments.store', ['current_team' => $this->team->slug, 'application' => $this->application->slug]),
            ($this->environment)('organization'),
        )
        ->assertSessionHasErrors(['name' => 'This name is reserved for the organization-wide alert rules.']);

    expect(Environment::query()->count())->toBe(0);
});

test('an environment cannot be renamed to the name of the organization rules scope', function () {
    $environment = Environment::factory()->for($this->application)->staging()->create();

    $this->actingAs($this->admin)
        ->patch(
            route('environments.update', ['current_team' => $this->team->slug, 'environment' => $environment->slug]),
            ($this->environment)('organization'),
        )
        ->assertSessionHasErrors('name');

    expect($environment->fresh()->name)->toBe('staging');
});

test('the wizard refuses the name too', function () {
    $this->actingAs($this->admin)
        ->post(route('applications.store', ['current_team' => $this->team->slug]), [
            'application' => ['name' => 'Reserved', 'host' => 'reserved.example.com'],
            'environments' => [($this->environment)('production'), ($this->environment)('organization')],
        ])
        ->assertSessionHasErrors('environments.1.name');

    expect(Application::query()->where('name', 'Reserved')->exists())->toBeFalse();
});

test('names that only contain the word stay allowed', function (string $name) {
    $this->actingAs($this->admin)
        ->post(
            route('environments.store', ['current_team' => $this->team->slug, 'application' => $this->application->slug]),
            ($this->environment)($name),
        )
        ->assertSessionHasNoErrors();

    expect(Environment::query()->where('name', $name)->exists())->toBeTrue();
})->with(['organization-eu', 'my-organization']);
