<?php

use App\Enums\EnvironmentColor;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Team;
use App\Models\User;
use App\Policies\EnvironmentPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->team = Team::factory()->create();

    $this->admin = User::factory()->create();
    $this->team->members()->attach($this->admin, ['role' => TeamRole::Admin->value]);

    $this->member = User::factory()->create();
    $this->team->members()->attach($this->member, ['role' => TeamRole::Member->value]);

    $this->viewer = User::factory()->create();
    $this->team->members()->attach($this->viewer, ['role' => TeamRole::Viewer->value]);

    $this->application = Application::factory()->for($this->team)->create(['name' => 'Fatturaomatic']);

    $this->validPayload = fn (array $overrides = []): array => array_merge([
        'name' => 'production',
        'color' => EnvironmentColor::Prod->value,
        'horizonUrl' => 'https://production.example.com/horizon/api',
        'basicAuthUser' => 'monitor',
        'basicAuthPassword' => 'super-secret-value',
        'pollIntervalSeconds' => 15,
    ], $overrides);
});

test('an admin creates an environment with a palette color, a valid url and credentials', function () {
    $response = $this->actingAs($this->admin)->post(
        route('environments.store', ['current_team' => $this->team->slug, 'application' => $this->application->slug]),
        ($this->validPayload)(),
    );

    $response->assertRedirect();

    $environment = Environment::where('application_id', $this->application->id)->where('name', 'production')->firstOrFail();

    expect($environment->slug)->toBe($this->application->slug.'-production')
        ->and($environment->color)->toBe(EnvironmentColor::Prod)
        ->and($environment->basic_auth_password)->toBe('super-secret-value')
        ->and($environment->getRawOriginal('basic_auth_password'))->not->toBe('super-secret-value');
});

test('the basic auth password never appears in an Inertia prop nor in the raw response', function () {
    $environment = Environment::factory()->for($this->application)->create([
        'basic_auth_user' => 'monitor',
        'basic_auth_password' => 'super-secret-value',
    ]);

    $response = $this->actingAs($this->admin)->get(route('environments.edit', [
        'current_team' => $this->team->slug,
        'environment' => $environment->slug,
    ]));

    $response->assertInertia(fn (Assert $page) => $page
        ->missing('page.environment.basicAuthPassword')
        ->where('page.hasPassword', true)
        ->where('page.environment.basicAuthUser', 'monitor'));

    expect($response->getContent())->not->toContain('super-secret-value');
});

test('updating an environment without a password keeps the existing one, sending one replaces it', function () {
    $environment = Environment::factory()->for($this->application)->create([
        'name' => 'production',
        'basic_auth_user' => 'monitor',
        'basic_auth_password' => 'original-secret',
    ]);

    $this->actingAs($this->admin)->patch(
        route('environments.update', ['current_team' => $this->team->slug, 'environment' => $environment->slug]),
        ($this->validPayload)(['basicAuthPassword' => null]),
    )->assertRedirect();

    $environment->refresh();
    expect($environment->basic_auth_password)->toBe('original-secret');

    $this->actingAs($this->admin)->patch(
        route('environments.update', ['current_team' => $this->team->slug, 'environment' => $environment->slug]),
        ($this->validPayload)(['basicAuthPassword' => 'new-secret']),
    )->assertRedirect();

    $environment->refresh();
    expect($environment->basic_auth_password)->toBe('new-secret');
});

test('updating an environment with a blank or whitespace-only password keeps the existing one', function (string $blank) {
    $environment = Environment::factory()->for($this->application)->create([
        'name' => 'production',
        'basic_auth_user' => 'monitor',
        'basic_auth_password' => 'original-secret',
    ]);

    $this->actingAs($this->admin)->patch(
        route('environments.update', ['current_team' => $this->team->slug, 'environment' => $environment->slug]),
        ($this->validPayload)(['basicAuthPassword' => $blank]),
    )->assertRedirect();

    $environment->refresh();
    expect($environment->basic_auth_password)->toBe('original-secret');
})->with(['', '   ']);

test('creating an environment with a blank password stores no credential', function () {
    $this->actingAs($this->admin)->post(
        route('environments.store', ['current_team' => $this->team->slug, 'application' => $this->application->slug]),
        ($this->validPayload)(['basicAuthPassword' => '']),
    )->assertRedirect();

    $environment = Environment::where('application_id', $this->application->id)->where('name', 'production')->firstOrFail();

    expect($environment->basic_auth_password)->toBeNull();
});

test('touching credentials requires the manage-credentials permission in addition to update', function () {
    $environment = Environment::factory()->for($this->application)->create([
        'name' => 'production',
        'basic_auth_user' => 'monitor',
        'basic_auth_password' => 'original-secret',
    ]);

    // Force-deny manageCredentials regardless of role, via a policy swap
    // rather than Gate::define(): a policy resolved for the model always
    // wins over a raw Gate::define() of the same name (Gate::resolveAuthCallback()
    // checks the policy first), so define() alone wouldn't actually override
    // EnvironmentPolicy here. Today's role matrix grants manageCredentials
    // to the same roles as ManageApplications, so this swap is the only way
    // to prove the controller consults it distinctly from "update".
    Gate::policy(Environment::class, get_class(new class extends EnvironmentPolicy
    {
        public function manageCredentials(User $user, Environment $environment): bool
        {
            return false;
        }
    }));

    // Untouched credentials: manageCredentials is never consulted, so the
    // forced denial above doesn't apply and the update goes through.
    $this->actingAs($this->admin)->patch(
        route('environments.update', ['current_team' => $this->team->slug, 'environment' => $environment->slug]),
        ($this->validPayload)(['name' => 'production', 'basicAuthUser' => null, 'basicAuthPassword' => null]),
    )->assertRedirect();

    // A new username touches credentials: denied even though "update"
    // itself would allow the admin through.
    $this->actingAs($this->admin)->patch(
        route('environments.update', ['current_team' => $this->team->slug, 'environment' => $environment->slug]),
        ($this->validPayload)(['name' => 'production', 'basicAuthUser' => 'someone-else', 'basicAuthPassword' => null]),
    )->assertForbidden();

    $environment->refresh();
    expect($environment->basic_auth_user)->toBeNull();

    // Same on the create path.
    $this->actingAs($this->admin)->post(
        route('environments.store', ['current_team' => $this->team->slug, 'application' => $this->application->slug]),
        ($this->validPayload)(['name' => 'staging']),
    )->assertForbidden();

    expect(Environment::where('application_id', $this->application->id)->where('name', 'staging')->exists())->toBeFalse();
});

test('an environment from another organization responds 404', function () {
    $otherTeam = Team::factory()->create();
    $otherApplication = Application::factory()->for($otherTeam)->create();
    $foreignEnvironment = Environment::factory()->for($otherApplication)->create();

    $this->actingAs($this->admin)
        ->get(route('environments.edit', ['current_team' => $this->team->slug, 'environment' => $foreignEnvironment->slug]))
        ->assertNotFound();

    $this->actingAs($this->admin)
        ->patch(
            route('environments.update', ['current_team' => $this->team->slug, 'environment' => $foreignEnvironment->slug]),
            ($this->validPayload)(),
        )
        ->assertNotFound();

    $this->actingAs($this->admin)
        ->delete(route('environments.destroy', ['current_team' => $this->team->slug, 'environment' => $foreignEnvironment->slug]), [
            'name' => $foreignEnvironment->name,
        ])
        ->assertNotFound();

    expect(Environment::find($foreignEnvironment->id))->not->toBeNull();
});

test('two organizations sharing the same environment slug each resolve their own', function () {
    $otherTeam = Team::factory()->create();
    $otherAdmin = User::factory()->create();
    $otherTeam->members()->attach($otherAdmin, ['role' => TeamRole::Admin->value]);
    // Same application name as $this->application, in a different
    // organization: application slugs are only unique per team, so this
    // legitimately produces the same slug in both organizations.
    $otherApplication = Application::factory()->for($otherTeam)->create(['name' => $this->application->name]);

    // basic_auth_* explicitly cleared: the "production" state otherwise
    // seeds credentials, which is irrelevant to what this test checks.
    $environment = Environment::factory()->for($this->application)->create([
        'name' => 'production',
        'basic_auth_user' => null,
        'basic_auth_password' => null,
    ]);
    $otherEnvironment = Environment::factory()->for($otherApplication)->create([
        'name' => 'production',
        'basic_auth_user' => null,
        'basic_auth_password' => null,
    ]);

    expect($otherApplication->slug)->toBe($this->application->slug)
        ->and($otherEnvironment->slug)->toBe($environment->slug);

    $this->actingAs($this->admin)
        ->get(route('environments.edit', ['current_team' => $this->team->slug, 'environment' => $environment->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('page.hasPassword', false));

    $this->actingAs($otherAdmin)
        ->get(route('environments.edit', ['current_team' => $otherTeam->slug, 'environment' => $otherEnvironment->slug]))
        ->assertOk();
});

test('poll interval out of range, a color outside the palette, and a url without a scheme are all rejected', function () {
    $route = route('environments.store', ['current_team' => $this->team->slug, 'application' => $this->application->slug]);

    $this->actingAs($this->admin)
        ->post($route, ($this->validPayload)(['pollIntervalSeconds' => 400]))
        ->assertInvalid(['pollIntervalSeconds']);

    $this->actingAs($this->admin)
        ->post($route, ($this->validPayload)(['color' => 'rainbow']))
        ->assertInvalid(['color']);

    $this->actingAs($this->admin)
        ->post($route, ($this->validPayload)(['horizonUrl' => 'production.example.com/horizon/api']))
        ->assertInvalid(['horizonUrl']);

    expect(Environment::where('application_id', $this->application->id)->count())->toBe(0);
});

test('deleting an environment requires typing its exact name, and clears manual visibility rows', function () {
    $environment = Environment::factory()->for($this->application)->create(['name' => 'staging']);

    $this->member->teamMemberships()->where('team_id', $this->team->id)->first()
        ->visibleEnvironments()->attach([$environment->id]);

    expect(DB::table('environment_user')->where('environment_id', $environment->id)->exists())->toBeTrue();

    $this->actingAs($this->admin)
        ->delete(route('environments.destroy', ['current_team' => $this->team->slug, 'environment' => $environment->slug]), [
            'name' => 'wrong name',
        ])
        ->assertInvalid(['name']);

    expect(Environment::find($environment->id))->not->toBeNull();

    $this->actingAs($this->admin)
        ->delete(route('environments.destroy', ['current_team' => $this->team->slug, 'environment' => $environment->slug]), [
            'name' => 'staging',
        ])
        ->assertRedirect();

    expect(Environment::find($environment->id))->toBeNull()
        ->and(DB::table('environment_user')->where('environment_id', $environment->id)->exists())->toBeFalse();
});

test('member and viewer cannot create, edit or delete environments', function (string $role) {
    $user = $role === 'member' ? $this->member : $this->viewer;

    $this->actingAs($user)
        ->get(route('environments.create', ['current_team' => $this->team->slug, 'application' => $this->application->slug]))
        ->assertForbidden();

    $this->actingAs($user)
        ->post(
            route('environments.store', ['current_team' => $this->team->slug, 'application' => $this->application->slug]),
            ($this->validPayload)(),
        )
        ->assertForbidden();

    $environment = Environment::factory()->for($this->application)->create(['name' => 'staging']);

    $this->actingAs($user)
        ->get(route('environments.edit', ['current_team' => $this->team->slug, 'environment' => $environment->slug]))
        ->assertForbidden();

    $this->actingAs($user)
        ->patch(
            route('environments.update', ['current_team' => $this->team->slug, 'environment' => $environment->slug]),
            ($this->validPayload)(['name' => 'staging']),
        )
        ->assertForbidden();

    $this->actingAs($user)
        ->delete(route('environments.destroy', ['current_team' => $this->team->slug, 'environment' => $environment->slug]), [
            'name' => 'staging',
        ])
        ->assertForbidden();

    expect(Environment::find($environment->id))->not->toBeNull();
})->with(['member', 'viewer']);
