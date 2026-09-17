<?php

use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->team = Team::factory()->create();

    $this->admin = User::factory()->create();
    $this->team->members()->attach($this->admin, ['role' => TeamRole::Admin->value]);

    $this->member = User::factory()->create();
    $this->team->members()->attach($this->member, ['role' => TeamRole::Member->value]);

    $this->viewer = User::factory()->create();
    $this->team->members()->attach($this->viewer, ['role' => TeamRole::Viewer->value]);

    $this->application = Application::factory()->for($this->team)->create(['name' => 'Invoicer']);

    $this->environment = Environment::factory()
        ->for($this->application)
        ->create(['basic_auth_user' => 'monitor', 'basic_auth_password' => 'ry3-lin3n-pillow']);

    $this->routes = fn (): array => [
        'monitoring/applications/Create' => route('applications.create', ['current_team' => $this->team->slug]),
        'monitoring/applications/Edit' => route('applications.edit', [
            'current_team' => $this->team->slug,
            'application' => $this->application->slug,
        ]),
        'monitoring/environments/Create' => route('environments.create', [
            'current_team' => $this->team->slug,
            'application' => $this->application->slug,
        ]),
        'monitoring/environments/Edit' => route('environments.edit', [
            'current_team' => $this->team->slug,
            'environment' => $this->environment->slug,
        ]),
    ];
});

test('the four form pages answer 200 for an admin', function () {
    foreach (($this->routes)() as $component => $url) {
        $this->actingAs($this->admin)
            ->get($url)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component($component)->has('page'));
    }
});

test('the four form pages answer 403 for a member and for a viewer', function (string $role) {
    $user = $role === 'member' ? $this->member : $this->viewer;

    foreach (($this->routes)() as $url) {
        $this->actingAs($user)->get($url)->assertForbidden();
    }
})->with(['member', 'viewer']);

test('the wizard receives the whole palette and nothing to edit', function () {
    $this->actingAs($this->admin)
        ->get(route('applications.create', ['current_team' => $this->team->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.application', null)
            ->where('page.slug', null)
            ->has('page.colors', 7)
            ->where('page.colors.0.value', 'prod')
            ->where('page.colors.0.label', 'Prod'));
});

test('the application edit page carries the slug its form submits to', function () {
    $this->actingAs($this->admin)
        ->get(route('applications.edit', [
            'current_team' => $this->team->slug,
            'application' => $this->application->slug,
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.slug', 'invoicer')
            ->where('page.application.name', 'Invoicer')
            ->where('page.application.host', $this->application->host));
});

test('the environment edit page shows the username and whether a password is set', function () {
    $this->actingAs($this->admin)
        ->get(route('environments.edit', [
            'current_team' => $this->team->slug,
            'environment' => $this->environment->slug,
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.slug', $this->environment->slug)
            ->where('page.applicationSlug', 'invoicer')
            ->where('page.environment.basicAuthUser', 'monitor')
            ->where('page.environment.pollingEnabled', true)
            ->where('page.hasPassword', true)
            ->has('page.colors', 7));
});

test('an environment without credentials reports no password set', function () {
    $bare = Environment::factory()->for($this->application)->create([
        'name' => 'staging',
        'basic_auth_user' => null,
        'basic_auth_password' => null,
        'polling_enabled' => false,
    ]);

    $this->actingAs($this->admin)
        ->get(route('environments.edit', [
            'current_team' => $this->team->slug,
            'environment' => $bare->slug,
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.environment.basicAuthUser', null)
            ->where('page.environment.pollingEnabled', false)
            ->where('page.hasPassword', false));
});

test('no form page prop is a password field, and none carries the stored one', function () {
    foreach (($this->routes)() as $url) {
        $props = $this->actingAs($this->admin)->get($url)->viewData('page');

        $keys = [];
        $walk = function (array $data) use (&$walk, &$keys): void {
            foreach ($data as $key => $value) {
                $keys[] = (string) $key;

                if (is_array($value)) {
                    $walk($value);
                }
            }
        };
        $walk($props);

        $passwordKeys = array_values(array_filter(
            $keys,
            fn (string $key) => str_contains(strtolower($key), 'password'),
        ));

        // "hasPassword" is the only thing about the credential a page may
        // say: a boolean. Any other password-ish prop is a leak.
        expect(array_unique($passwordKeys))->each->toBe('hasPassword');
        expect(json_encode($props, JSON_THROW_ON_ERROR))->not->toContain('ry3-lin3n-pillow');
    }
});

test('the applications list tells the front end whether the forms are reachable', function () {
    $this->actingAs($this->admin)
        ->get(route('applications.index', ['current_team' => $this->team->slug]))
        ->assertInertia(fn (Assert $page) => $page->where('canManageApplications', true));

    foreach ([$this->member, $this->viewer] as $user) {
        $this->actingAs($user)
            ->get(route('applications.index', ['current_team' => $this->team->slug]))
            ->assertInertia(fn (Assert $page) => $page->where('canManageApplications', false));
    }
});
