<?php

use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
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

test('the wizard receives every environment type and nothing to edit', function () {
    $this->actingAs($this->admin)
        ->get(route('applications.create', ['current_team' => $this->team->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.application', null)
            ->where('page.slug', null)
            ->has('page.types', 6)
            ->where('page.types.0.value', 'production')
            ->where('page.types.0.label', 'Production'));
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
            ->missing('page.application.host'));
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
            ->has('page.types', 6)->where('page.environment.type', 'production'));
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

test('the add environment page names the domain and the base of its URL suggestion', function () {
    Environment::factory()->for($this->application)->staging()->create();

    $this->actingAs($this->admin)
        ->get(route('environments.create', ['current_team' => $this->team->slug, 'application' => $this->application->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.domain', 'production.example.com')
            ->where('page.baseHorizonUrl', 'https://production.example.com/horizon')
            ->where('page.baseEnvironmentName', 'production'));
});

test('without a production environment the suggestion starts from the first one', function () {
    $this->environment->delete();
    Environment::factory()->for($this->application)->staging()->create();
    Environment::factory()->for($this->application)->develop()->create();

    $this->actingAs($this->admin)
        ->get(route('environments.create', ['current_team' => $this->team->slug, 'application' => $this->application->slug]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.domain', 'staging.example.com')
            ->where('page.baseEnvironmentName', 'staging'));
});

test('a legacy Horizon URL with credentials inside never reaches the add environment page', function () {
    DB::table('environments')->where('id', $this->environment->id)
        ->update(['horizon_url' => 'https://monitor:ry3-lin3n-pillow@Production.Example.com/horizon/api']);

    $this->actingAs($this->admin)
        ->get(route('environments.create', ['current_team' => $this->team->slug, 'application' => $this->application->slug]))
        ->assertDontSee('ry3-lin3n-pillow', false)
        ->assertDontSee('monitor:', false)
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.domain', 'production.example.com')
            ->where('page.baseHorizonUrl', fn (string $url) => ! str_contains($url, '@')));

    $this->actingAs($this->admin)
        ->get(route('applications.index', ['current_team' => $this->team->slug]))
        ->assertDontSee('ry3-lin3n-pillow', false)
        ->assertDontSee('monitor:', false);
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

test('the wizard and the environment pages share one environment form', function () {
    $wizard = file_get_contents(resource_path('js/pages/monitoring/applications/Create.vue'));

    expect($wizard)->toContain('<EnvironmentForm');

    foreach (['basicAuthUser', 'basicAuthPassword', 'pollIntervalSeconds', 'pollingEnabled'] as $field) {
        expect($wizard)->not->toContain($field);
    }
});

test('identity follows the environment type everywhere', function () {
    $css = file_get_contents(resource_path('css/nocturne.css'));

    foreach (['production', 'preprod', 'staging', 'development', 'testing', 'demo'] as $type) {
        expect($css)->toContain("--env-{$type}:");
    }

    expect($css)->not->toContain('--env-prod:')->not->toContain('--env-worker:')->not->toContain('--env-develop:')
        ->and(file_exists(resource_path('js/components/monitoring/applications/ColorPicker.vue')))->toBeFalse()
        ->and(file_get_contents(resource_path('js/components/nocturne/EnvPill.vue')))->toContain('isProductionType(type)');
});

test('the environment form asks for a type and the full Horizon URL, and the environment page re-derives no API path', function () {
    $form = file_get_contents(resource_path('js/components/monitoring/applications/EnvironmentForm.vue'));
    $create = file_get_contents(resource_path('js/pages/monitoring/environments/Create.vue'));
    $show = file_get_contents(resource_path('js/pages/monitoring/environments/Show.vue'));

    expect($form)
        ->toContain('<TypePicker')
        ->toContain("'The address of the Horizon dashboard, as you open it in the browser.'")
        ->toContain('placeholder="https://shop.example.com/horizon"')
        ->toContain('followSuggestion(')
        ->not->toContain('isProduction')
        ->not->toContain('productionEdited')
        ->not->toContain('Hidden from members who see everything except production.');

    expect($create)
        ->toContain('useSuggestedHorizonUrl(')
        ->toContain('@url-input="onUrlInput"')
        ->toContain('type-follows-name')
        ->not->toContain('urlEdited')
        ->not->toContain('application.host');
    expect($show)->not->toContain('mastersPath');
});

test('the wizard asks only the application name before the environments', function () {
    $wizard = file_get_contents(resource_path('js/pages/monitoring/applications/Create.vue'));
    $form = file_get_contents(resource_path('js/components/monitoring/applications/ApplicationForm.vue'));

    expect($wizard)->not->toContain('horizonPath')->not->toContain('application.host')->toContain('type-follows-name')
        ->and($form)->not->toContain('host');
});

test('every environment form page sends the collection interval limits from the configuration', function () {
    config([
        'horizon-watch.readings.poll_interval_seconds.default' => 30,
        'horizon-watch.readings.poll_interval_seconds.min' => 15,
        'horizon-watch.readings.poll_interval_seconds.max' => 600,
    ]);

    $pages = collect(($this->routes)())->only(['monitoring/applications/Create', 'monitoring/environments/Create', 'monitoring/environments/Edit']);

    expect($pages)->toHaveCount(3);

    foreach ($pages as $url) {
        $this->actingAs($this->admin)
            ->get($url)
            ->assertInertia(fn (Assert $page) => $page
                ->where('page.pollInterval.default', 30)
                ->where('page.pollInterval.min', 15)
                ->where('page.pollInterval.max', 600)
                ->where('page.pollInterval.step', 15));
    }
});
