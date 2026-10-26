<?php

use App\Enums\EnvironmentType;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->admin = User::factory()->create();
    $this->team->members()->attach($this->admin, ['role' => TeamRole::Admin->value]);
    $this->application = Application::factory()->for($this->team)->create(['name' => 'Acme Shop']);

    $make = fn (string $name, EnvironmentType $type, ?string $user, ?string $password, bool $polling) => Environment::factory()->for($this->application)->create([
        'name' => $name,
        'type' => $type,
        'horizon_url' => "https://{$name}.shop.example.com/horizon",
        'basic_auth_user' => $user,
        'basic_auth_password' => $password,
        'poll_interval_seconds' => 60,
        'polling_enabled' => $polling,
    ]);

    $this->waiting = $make('production', EnvironmentType::Production, 'ops', null, false);
    $this->set = $make('staging', EnvironmentType::Staging, 'ops', 'stored-secret', true);
    $this->open = $make('demo', EnvironmentType::Demo, null, null, true);
    $this->route = fn (string $name, array $parameters) => route($name, ['current_team' => $this->team->slug, ...$parameters]);
});

test('the application page marks the environment that waits for its password', function () {
    $this->actingAs($this->admin)->get(($this->route)('applications.show', ['application' => $this->application->slug]))
        ->assertInertia(fn (Assert $page) => $page->where(
            'page.environments',
            fn (Collection $environments) => $environments->pluck('passwordNeeded', 'name')->sortKeys()->all()
                === ['demo' => false, 'production' => true, 'staging' => false],
        ));
});

test('the environment page and the edit form mark it too, until the password is typed', function () {
    $this->actingAs($this->admin)->get(($this->route)('environments.show', ['environment' => $this->waiting->slug]))
        ->assertInertia(fn (Assert $page) => $page->where('page.environment.passwordNeeded', true));

    // The edit form derives the mark from the username and hasPassword it already receives.
    $this->actingAs($this->admin)->get(($this->route)('environments.edit', ['environment' => $this->waiting->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.environment.basicAuthUser', 'ops')
            ->where('page.hasPassword', false)
            ->missing('page.passwordNeeded'));

    $this->actingAs($this->admin)->get(($this->route)('environments.edit', ['environment' => $this->set->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.environment.basicAuthUser', 'ops')
            ->where('page.hasPassword', true));

    $this->waiting->forceFill(['basic_auth_password' => 'typed-at-last'])->save();

    $this->actingAs($this->admin)->get(($this->route)('environments.show', ['environment' => $this->waiting->slug]))
        ->assertInertia(fn (Assert $page) => $page->where('page.environment.passwordNeeded', false));

    $this->actingAs($this->admin)->get(($this->route)('environments.edit', ['environment' => $this->waiting->slug]))
        ->assertInertia(fn (Assert $page) => $page->where('page.hasPassword', true));
});

test('the pages draw the mark with the shared tag', function () {
    foreach ([
        'js/components/monitoring/applications/EnvironmentCard.vue' => ['v-if="environment.passwordNeeded"'],
        'js/pages/monitoring/environments/Show.vue' => ['v-if="environment.passwordNeeded"'],
        'js/components/mobile/environment/EnvironmentMobile.vue' => ['v-if="environment.passwordNeeded"'],
        'js/pages/monitoring/environments/Edit.vue' => [
            'const passwordNeeded = computed(',
            '!page.hasPassword',
            'v-if="passwordNeeded"',
        ],
    ] as $path => $conditions) {
        expect(file_get_contents(resource_path($path)))
            ->toContain('<PasswordNeededTag')
            ->toContain(...$conditions);
    }
});
