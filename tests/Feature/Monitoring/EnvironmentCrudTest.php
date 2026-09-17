<?php

use App\Actions\Environments\UpdateEnvironment;
use App\Data\Applications\EnvironmentFormData;
use App\Enums\EnvironmentColor;
use App\Enums\MemberVisibility;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentSnapshot;
use App\Models\EnvironmentState;
use App\Models\Team;
use App\Models\User;
use App\Policies\EnvironmentPolicy;
use Carbon\CarbonImmutable;
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

test('the edit page never shows credentials saved inside a legacy url', function (string $saved, string $shown) {
    $environment = Environment::factory()->for($this->application)->create();
    DB::table('environments')->where('id', $environment->id)->update(['horizon_url' => $saved]);

    $response = $this->actingAs($this->admin)->get(route('environments.edit', [
        'current_team' => $this->team->slug,
        'environment' => $environment->slug,
    ]));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('page.environment.horizonUrl', $shown));

    expect($response->getContent())
        ->not->toContain('legacy-owner')
        ->not->toContain('legacy-url-secret');
})->with([
    'user and password' => ['https://legacy-owner:legacy-url-secret@legacy.example.com/horizon', 'https://legacy.example.com/horizon'],
    'user only' => ['http://legacy-owner@legacy.example.com:8080/horizon', 'http://legacy.example.com:8080/horizon'],
    'upper-case scheme' => ['HTTPS://legacy-owner:legacy-url-secret@legacy.example.com/horizon', 'HTTPS://legacy.example.com/horizon'],
]);

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

test('creating an environment with neither half stores no credential', function () {
    $this->actingAs($this->admin)->post(
        route('environments.store', ['current_team' => $this->team->slug, 'application' => $this->application->slug]),
        ($this->validPayload)(['basicAuthUser' => null, 'basicAuthPassword' => '']),
    )->assertRedirect();

    $environment = Environment::where('application_id', $this->application->id)->where('name', 'production')->firstOrFail();

    expect($environment->basic_auth_user)->toBeNull()
        ->and($environment->basic_auth_password)->toBeNull();
});

test('a username with no password is rejected on create but allowed on update', function () {
    $this->actingAs($this->admin)->post(
        route('environments.store', ['current_team' => $this->team->slug, 'application' => $this->application->slug]),
        ($this->validPayload)(['basicAuthUser' => 'monitor', 'basicAuthPassword' => '']),
    )->assertInvalid(['basicAuthPassword']);

    expect(Environment::where('application_id', $this->application->id)->count())->toBe(0);

    $environment = Environment::factory()->for($this->application)->create([
        'name' => 'production',
        'basic_auth_user' => 'monitor',
        'basic_auth_password' => 'original-secret',
    ]);

    $this->actingAs($this->admin)->patch(
        route('environments.update', ['current_team' => $this->team->slug, 'environment' => $environment->slug]),
        ($this->validPayload)(['basicAuthUser' => 'monitor', 'basicAuthPassword' => '']),
    )->assertRedirect();

    expect($environment->refresh()->basic_auth_password)->toBe('original-secret');
});

test('a username of whitespace, or one carrying a colon, is rejected', function (string $username) {
    $this->actingAs($this->admin)->post(
        route('environments.store', ['current_team' => $this->team->slug, 'application' => $this->application->slug]),
        ($this->validPayload)(['basicAuthUser' => $username, 'basicAuthPassword' => 'secret-value']),
    )->assertInvalid(['basicAuthUser']);

    expect(Environment::where('application_id', $this->application->id)->count())->toBe(0);
})->with([
    '   ',
    'mon:itor',
]);

test('touching credentials requires the manage-credentials permission in addition to update', function () {
    $environment = Environment::factory()->for($this->application)->create([
        'name' => 'production',
        'basic_auth_user' => 'monitor',
        'basic_auth_password' => 'original-secret',
    ]);

    Gate::policy(Environment::class, get_class(new class extends EnvironmentPolicy
    {
        public function manageCredentials(User $user, Environment $environment): bool
        {
            return false;
        }
    }));

    $bare = Environment::factory()->for($this->application)->create([
        'name' => 'develop',
        'basic_auth_user' => null,
        'basic_auth_password' => null,
    ]);

    $this->actingAs($this->admin)->patch(
        route('environments.update', ['current_team' => $this->team->slug, 'environment' => $bare->slug]),
        ($this->validPayload)(['name' => 'develop', 'basicAuthUser' => null, 'basicAuthPassword' => null]),
    )->assertRedirect();

    $this->actingAs($this->admin)->patch(
        route('environments.update', ['current_team' => $this->team->slug, 'environment' => $bare->slug]),
        ($this->validPayload)(['name' => 'develop', 'basicAuthUser' => 'someone-else', 'basicAuthPassword' => null]),
    )->assertForbidden();

    $bare->refresh();
    expect($bare->basic_auth_user)->toBeNull();

    $this->actingAs($this->admin)->patch(
        route('environments.update', ['current_team' => $this->team->slug, 'environment' => $environment->slug]),
        ($this->validPayload)([
            'name' => 'production',
            'basicAuthUser' => 'monitor',
            'basicAuthPassword' => null,
            'pollIntervalSeconds' => 45,
        ]),
    )->assertRedirect();

    $environment->refresh();
    expect($environment->poll_interval_seconds)->toBe(45)
        ->and($environment->basic_auth_user)->toBe('monitor')
        ->and($environment->basic_auth_password)->toBe('original-secret');

    $this->actingAs($this->admin)->patch(
        route('environments.update', ['current_team' => $this->team->slug, 'environment' => $environment->slug]),
        ($this->validPayload)(['name' => 'production', 'basicAuthUser' => 'someone-else', 'basicAuthPassword' => null]),
    )->assertForbidden();

    expect($environment->refresh()->basic_auth_user)->toBe('monitor');

    $this->actingAs($this->admin)->patch(
        route('environments.update', ['current_team' => $this->team->slug, 'environment' => $environment->slug]),
        ($this->validPayload)(['name' => 'production', 'basicAuthUser' => null, 'basicAuthPassword' => null]),
    )->assertForbidden();

    $environment->refresh();
    expect($environment->basic_auth_user)->toBe('monitor')
        ->and($environment->basic_auth_password)->toBe('original-secret');

    $this->actingAs($this->admin)->post(
        route('environments.store', ['current_team' => $this->team->slug, 'application' => $this->application->slug]),
        ($this->validPayload)(['name' => 'staging']),
    )->assertForbidden();

    expect(Environment::where('application_id', $this->application->id)->where('name', 'staging')->exists())->toBeFalse();
});

test('clearing the username clears the stored password with it', function () {
    $environment = Environment::factory()->for($this->application)->create([
        'name' => 'production',
        'basic_auth_user' => 'monitor',
        'basic_auth_password' => 'original-secret',
    ]);

    $this->actingAs($this->admin)->patch(
        route('environments.update', ['current_team' => $this->team->slug, 'environment' => $environment->slug]),
        ($this->validPayload)(['basicAuthUser' => null, 'basicAuthPassword' => null]),
    )->assertRedirect();

    $environment->refresh();

    expect($environment->basic_auth_user)->toBeNull()
        ->and($environment->basic_auth_password)->toBeNull()
        ->and($environment->getRawOriginal('basic_auth_password'))->toBeNull();
});

test('a password without a username is rejected instead of stored unusable', function () {
    $this->actingAs($this->admin)->post(
        route('environments.store', ['current_team' => $this->team->slug, 'application' => $this->application->slug]),
        ($this->validPayload)(['basicAuthUser' => null, 'basicAuthPassword' => 'orphan-secret']),
    )->assertInvalid(['basicAuthUser']);

    expect(Environment::where('application_id', $this->application->id)->count())->toBe(0);
});

test('a failed validation does not flash the basic-auth password into the session', function () {
    $this->actingAs($this->admin)->post(
        route('environments.store', ['current_team' => $this->team->slug, 'application' => $this->application->slug]),
        ($this->validPayload)(['basicAuthPassword' => 'plaintext-must-not-persist', 'pollIntervalSeconds' => 9999]),
    )->assertInvalid(['pollIntervalSeconds']);

    expect(json_encode(session()->all(), JSON_THROW_ON_ERROR))
        ->not->toContain('plaintext-must-not-persist')
        ->toContain('monitor');
});

test('an environment name is unique per application, and a field error says so', function () {
    Environment::factory()->for($this->application)->create(['name' => 'production']);

    $this->actingAs($this->admin)
        ->post(
            route('environments.store', ['current_team' => $this->team->slug, 'application' => $this->application->slug]),
            ($this->validPayload)(['name' => 'production']),
        )
        ->assertInvalid(['name']);

    expect(Environment::where('application_id', $this->application->id)->count())->toBe(1);

    $other = Application::factory()->for($this->team)->create(['name' => 'Other']);

    $this->actingAs($this->admin)
        ->post(
            route('environments.store', ['current_team' => $this->team->slug, 'application' => $other->slug]),
            ($this->validPayload)(['name' => 'production']),
        )
        ->assertRedirect();

    expect(Environment::where('application_id', $other->id)->count())->toBe(1);
});

test('renaming an environment onto a sibling is rejected, keeping its own name is not', function () {
    Environment::factory()->for($this->application)->create(['name' => 'production']);
    $staging = Environment::factory()->for($this->application)->create([
        'name' => 'staging',
        'basic_auth_user' => null,
        'basic_auth_password' => null,
    ]);

    $this->actingAs($this->admin)
        ->patch(
            route('environments.update', ['current_team' => $this->team->slug, 'environment' => $staging->slug]),
            ($this->validPayload)(['name' => 'production', 'basicAuthUser' => null, 'basicAuthPassword' => null]),
        )
        ->assertInvalid(['name']);

    expect($staging->refresh()->name)->toBe('staging');

    $this->actingAs($this->admin)
        ->patch(
            route('environments.update', ['current_team' => $this->team->slug, 'environment' => $staging->slug]),
            ($this->validPayload)([
                'name' => 'staging',
                'pollIntervalSeconds' => 30,
                'basicAuthUser' => null,
                'basicAuthPassword' => null,
            ]),
        )
        ->assertRedirect();

    expect($staging->refresh()->poll_interval_seconds)->toBe(30);
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
    $otherApplication = Application::factory()->for($otherTeam)->create(['name' => $this->application->name]);

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

test('an admin edits an environment their own visibility hides', function () {
    $environment = Environment::factory()->for($this->application)->create([
        'name' => 'production',
        'basic_auth_user' => 'monitor',
        'basic_auth_password' => 'old-secret-value',
    ]);

    $this->admin->teamMemberships()->where('team_id', $this->team->id)->first()
        ->update(['visibility' => MemberVisibility::Manual->value]);

    $this->actingAs($this->admin)
        ->get(route('environments.edit', ['current_team' => $this->team->slug, 'environment' => $environment->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.environment.name', 'production')
            ->where('page.hasPassword', true));

    $this->actingAs($this->admin)
        ->patch(route('environments.update', ['current_team' => $this->team->slug, 'environment' => $environment->slug]), [
            'name' => 'production',
            'color' => EnvironmentColor::Prod->value,
            'horizonUrl' => 'https://production.example.com/horizon/api',
            'basicAuthUser' => 'monitor',
            'basicAuthPassword' => 'new-secret-value',
            'pollIntervalSeconds' => 15,
        ])
        ->assertRedirect();

    expect($environment->fresh()->basic_auth_password)->toBe('new-secret-value');

    $this->actingAs($this->admin)
        ->get(route('environments.show', ['current_team' => $this->team->slug, 'environment' => $environment->slug]))
        ->assertNotFound();
});

test('collection is on unless the form turns it off', function () {
    $store = route('environments.store', ['current_team' => $this->team->slug, 'application' => $this->application->slug]);

    $this->actingAs($this->admin)
        ->post($store, ($this->validPayload)(['name' => 'production']))
        ->assertRedirect();

    $this->actingAs($this->admin)
        ->post($store, ($this->validPayload)(['name' => 'staging', 'pollingEnabled' => false]))
        ->assertRedirect();

    expect(Environment::where('name', 'production')->firstOrFail()->polling_enabled)->toBeTrue()
        ->and(Environment::where('name', 'staging')->firstOrFail()->polling_enabled)->toBeFalse();
});

test('pausing and resuming collection is an edit, without the credentials permission', function () {
    Gate::policy(Environment::class, get_class(new class extends EnvironmentPolicy
    {
        public function manageCredentials(User $user, Environment $environment): bool
        {
            return false;
        }
    }));

    $environment = Environment::factory()->for($this->application)->create([
        'name' => 'production',
        'basic_auth_user' => 'monitor',
        'basic_auth_password' => 'super-secret-value',
    ]);
    $update = route('environments.update', ['current_team' => $this->team->slug, 'environment' => $environment->slug]);
    $edit = route('environments.edit', ['current_team' => $this->team->slug, 'environment' => $environment->slug]);
    $payload = ($this->validPayload)(['basicAuthPassword' => null]);

    $this->actingAs($this->admin)->get($edit)
        ->assertInertia(fn (Assert $page) => $page->where('page.environment.pollingEnabled', true));

    $this->actingAs($this->admin)->patch($update, [...$payload, 'pollingEnabled' => false])->assertRedirect();

    expect($environment->fresh()->polling_enabled)->toBeFalse()
        ->and($environment->fresh()->basic_auth_password)->toBe('super-secret-value');

    $this->actingAs($this->admin)->get($edit)
        ->assertInertia(fn (Assert $page) => $page->where('page.environment.pollingEnabled', false));

    $this->actingAs($this->admin)->patch($update, [...$payload, 'pollingEnabled' => true])->assertRedirect();

    expect($environment->fresh()->polling_enabled)->toBeTrue();
});

test('collection must be a boolean', function () {
    $this->actingAs($this->admin)
        ->post(
            route('environments.store', ['current_team' => $this->team->slug, 'application' => $this->application->slug]),
            ($this->validPayload)(['pollingEnabled' => 'sometimes']),
        )
        ->assertSessionHasErrors('pollingEnabled');

    expect(Environment::where('application_id', $this->application->id)->exists())->toBeFalse();
});

dataset('urls carrying credentials', [
    'user and password' => ['https://ops:url-secret@horizon.example.com/horizon'],
    'user only' => ['https://url-secret@horizon.example.com/horizon'],
    'bare at sign' => ['https://@horizon.example.com/horizon'],
    'upper-case scheme' => ['HTTPS://ops:url-secret@horizon.example.com/horizon'],
    'at sign behind a backslash' => ['https://horizon.example.com\\url-secret@horizon.example.net/horizon'],
]);

test('a Horizon URL carrying credentials is refused on create and update, and not flashed back', function (string $url) {
    $environment = Environment::factory()->for($this->application)->create([
        'name' => 'staging',
        'horizon_url' => 'https://staging.example.com/horizon',
    ]);

    $this->actingAs($this->admin)
        ->post(
            route('environments.store', ['current_team' => $this->team->slug, 'application' => $this->application->slug]),
            ($this->validPayload)(['horizonUrl' => $url]),
        )
        ->assertInvalid(['horizonUrl' => 'basic-auth fields']);

    expect(json_encode(session()->all(), JSON_THROW_ON_ERROR))->not->toContain('url-secret')
        ->and(Environment::where('name', 'production')->exists())->toBeFalse();

    $this->actingAs($this->admin)
        ->patch(
            route('environments.update', ['current_team' => $this->team->slug, 'environment' => $environment->slug]),
            ($this->validPayload)(['name' => 'staging', 'horizonUrl' => $url]),
        )
        ->assertInvalid(['horizonUrl' => 'basic-auth fields']);

    expect(json_encode(session()->all(), JSON_THROW_ON_ERROR))->not->toContain('url-secret')
        ->and($environment->fresh()->horizon_url)->toBe('https://staging.example.com/horizon');
})->with('urls carrying credentials');

test('an at sign after the host is not a credential', function () {
    $this->actingAs($this->admin)
        ->post(
            route('environments.store', ['current_team' => $this->team->slug, 'application' => $this->application->slug]),
            ($this->validPayload)(['horizonUrl' => 'https://horizon.example.com/ops@team/horizon']),
        )
        ->assertValid();

    expect(Environment::where('name', 'production')->sole()->horizon_url)
        ->toBe('https://horizon.example.com/ops@team/horizon');
});

test('an at sign in a query string is refused for the query, not as a credential', function () {
    $this->actingAs($this->admin)
        ->post(
            route('environments.store', ['current_team' => $this->team->slug, 'application' => $this->application->slug]),
            ($this->validPayload)(['name' => 'staging', 'horizonUrl' => 'https://horizon.example.com/ops@team/horizon?by=a@b']),
        )
        ->assertInvalid(['horizonUrl' => 'query string']);

    expect(session('errors')->get('horizonUrl'))
        ->toBe(['Use the address of the Horizon dashboard, without a query string or a fragment.'])
        ->and(Environment::where('name', 'staging')->exists())->toBeFalse();
});

dataset('another address', [
    'another host' => ['https://attacker.example.net/collect'],
    'another scheme' => ['http://production.example.com/horizon'],
    'another port' => ['https://production.example.com:8443/horizon'],
]);

test('moving an environment with a stored password to another address needs the password typed again', function (string $url) {
    $environment = Environment::factory()->for($this->application)->create([
        'name' => 'production',
        'horizon_url' => 'https://production.example.com/horizon',
        'basic_auth_user' => 'monitor',
        'basic_auth_password' => 'original-secret',
    ]);
    $update = route('environments.update', ['current_team' => $this->team->slug, 'environment' => $environment->slug]);

    $this->actingAs($this->admin)
        ->patch($update, ($this->validPayload)(['horizonUrl' => $url, 'basicAuthPassword' => '']))
        ->assertInvalid(['basicAuthPassword' => 'Type the password again']);

    expect($environment->fresh()->horizon_url)->toBe('https://production.example.com/horizon')
        ->and($environment->fresh()->basic_auth_password)->toBe('original-secret');

    $this->actingAs($this->admin)
        ->patch($update, ($this->validPayload)(['horizonUrl' => $url, 'basicAuthPassword' => 'retyped-secret']))
        ->assertValid()
        ->assertRedirect();

    expect($environment->fresh()->horizon_url)->toBe($url)
        ->and($environment->fresh()->basic_auth_password)->toBe('retyped-secret');
})->with('another address');

test('a path-only move keeps the stored password, and so does a new username on the same address', function () {
    $environment = Environment::factory()->for($this->application)->create([
        'name' => 'production',
        'horizon_url' => 'https://production.example.com/horizon',
        'basic_auth_user' => 'monitor',
        'basic_auth_password' => 'original-secret',
    ]);
    $update = route('environments.update', ['current_team' => $this->team->slug, 'environment' => $environment->slug]);

    $this->actingAs($this->admin)
        ->patch($update, ($this->validPayload)(['horizonUrl' => 'https://PRODUCTION.example.com:443/ops/horizon', 'basicAuthPassword' => null]))
        ->assertValid();

    expect($environment->fresh()->horizon_url)->toBe('https://PRODUCTION.example.com:443/ops/horizon')
        ->and($environment->fresh()->basic_auth_password)->toBe('original-secret');

    $this->actingAs($this->admin)
        ->patch($update, ($this->validPayload)(['horizonUrl' => 'https://production.example.com/ops/horizon', 'basicAuthUser' => 'renamed', 'basicAuthPassword' => null]))
        ->assertValid();

    expect($environment->fresh()->basic_auth_user)->toBe('renamed')
        ->and($environment->fresh()->basic_auth_password)->toBe('original-secret');
});

test('clearing the username while moving the address clears the password without asking for it', function () {
    $environment = Environment::factory()->for($this->application)->create([
        'name' => 'production',
        'horizon_url' => 'https://production.example.com/horizon',
        'basic_auth_user' => 'monitor',
        'basic_auth_password' => 'original-secret',
    ]);

    $this->actingAs($this->admin)
        ->patch(
            route('environments.update', ['current_team' => $this->team->slug, 'environment' => $environment->slug]),
            ($this->validPayload)(['horizonUrl' => 'https://elsewhere.example.net/horizon', 'basicAuthUser' => null, 'basicAuthPassword' => null]),
        )
        ->assertValid();

    expect($environment->fresh()->horizon_url)->toBe('https://elsewhere.example.net/horizon')
        ->and($environment->fresh()->basic_auth_password)->toBeNull();
});

test('an address change is a credentials change only when a password is on file', function () {
    Gate::policy(Environment::class, get_class(new class extends EnvironmentPolicy
    {
        public function manageCredentials(User $user, Environment $environment): bool
        {
            return false;
        }
    }));

    $credentialed = Environment::factory()->for($this->application)->create([
        'name' => 'production',
        'horizon_url' => 'https://production.example.com/horizon',
        'basic_auth_user' => 'monitor',
        'basic_auth_password' => 'original-secret',
    ]);
    $bare = Environment::factory()->for($this->application)->create([
        'name' => 'staging',
        'horizon_url' => 'https://staging.example.com/horizon',
        'basic_auth_user' => null,
        'basic_auth_password' => null,
    ]);
    $update = fn (Environment $environment) => route('environments.update', ['current_team' => $this->team->slug, 'environment' => $environment->slug]);

    $this->actingAs($this->admin)
        ->patch($update($credentialed), ($this->validPayload)(['horizonUrl' => 'https://attacker.example.net/collect', 'basicAuthPassword' => 'retyped']))
        ->assertForbidden();

    expect($credentialed->fresh()->horizon_url)->toBe('https://production.example.com/horizon')
        ->and($credentialed->fresh()->basic_auth_password)->toBe('original-secret');

    $this->actingAs($this->admin)
        ->patch($update($credentialed), ($this->validPayload)(['horizonUrl' => 'https://production.example.com/ops/horizon', 'basicAuthPassword' => null]))
        ->assertRedirect();

    expect($credentialed->fresh()->horizon_url)->toBe('https://production.example.com/ops/horizon');

    $this->actingAs($this->admin)
        ->patch($update($bare), ($this->validPayload)(['name' => 'staging', 'horizonUrl' => 'https://elsewhere.example.net/horizon', 'basicAuthUser' => null, 'basicAuthPassword' => null]))
        ->assertRedirect();

    expect($bare->fresh()->horizon_url)->toBe('https://elsewhere.example.net/horizon');
});

test('the update action refuses to carry the stored password to a new address without validation', function () {
    $environment = Environment::factory()->for($this->application)->create([
        'name' => 'production',
        'horizon_url' => 'https://production.example.com/horizon',
        'basic_auth_user' => 'monitor',
        'basic_auth_password' => 'original-secret',
    ]);

    expect(fn () => app(UpdateEnvironment::class)->handle($environment, EnvironmentFormData::from(($this->validPayload)([
        'horizonUrl' => 'https://attacker.example.net/collect',
        'basicAuthPassword' => null,
    ]))))->toThrow(LogicException::class);

    expect($environment->fresh()->horizon_url)->toBe('https://production.example.com/horizon');
});

test('changesCredentialsOf counts a new address as a credentials change only when a password is on file', function () {
    $credentialed = Environment::factory()->for($this->application)->create([
        'name' => 'production',
        'horizon_url' => 'https://production.example.com/horizon',
        'basic_auth_user' => 'monitor',
        'basic_auth_password' => 'original-secret',
    ]);
    $bare = Environment::factory()->for($this->application)->create([
        'name' => 'staging',
        'horizon_url' => 'https://production.example.com/horizon',
        'basic_auth_user' => 'monitor',
        'basic_auth_password' => null,
    ]);
    $data = fn (string $url) => EnvironmentFormData::from(($this->validPayload)(['horizonUrl' => $url, 'basicAuthPassword' => null]));

    expect($data('https://attacker.example.net/collect')->changesCredentialsOf($credentialed))->toBeTrue()
        ->and($data('https://production.example.com/ops/horizon')->changesCredentialsOf($credentialed))->toBeFalse()
        ->and($data('https://attacker.example.net/collect')->changesCredentialsOf($bare))->toBeFalse();
});

test('the poll interval goes from the scheduler tick of 15 seconds to 300', function (int $seconds, bool $valid) {
    $response = $this->actingAs($this->admin)->post(
        route('environments.store', ['current_team' => $this->team->slug, 'application' => $this->application->slug]),
        ($this->validPayload)(['pollIntervalSeconds' => $seconds]),
    );

    $valid ? $response->assertValid() : $response->assertInvalid(['pollIntervalSeconds']);
})->with([
    [5, false],
    [14, false],
    [15, true],
    [300, true],
    [301, false],
]);

describe('the next reading after an edit', function () {
    beforeEach(function () {
        $this->travelTo(CarbonImmutable::parse('2026-09-17 10:00:00.600'));

        $this->edited = fn (array $attributes, ?string $nextPollAt): Environment => tap(
            Environment::factory()->for($this->application)->create([
                'name' => 'production',
                'basic_auth_user' => 'monitor',
                'basic_auth_password' => 'super-secret-value',
                'horizon_url' => 'https://production.example.com/horizon',
                ...$attributes,
            ]),
            fn (Environment $environment) => $environment->forceFill(['next_poll_at' => $nextPollAt])->save(),
        );

        $this->save = fn (Environment $environment, array $overrides) => $this->actingAs($this->admin)->patch(
            route('environments.update', ['current_team' => $this->team->slug, 'environment' => $environment->slug]),
            ($this->validPayload)([
                'horizonUrl' => 'https://production.example.com/horizon',
                'basicAuthPassword' => null,
                ...$overrides,
            ]),
        )->assertRedirect();

        $this->nextPollAt = fn (Environment $environment): ?string => $environment->fresh()->next_poll_at?->toDateTimeString();
    });

    test('a shorter interval brings it within the new interval', function () {
        $environment = ($this->edited)(['poll_interval_seconds' => 300], '2026-09-17 10:04:50');

        ($this->save)($environment, ['pollIntervalSeconds' => 15]);

        expect(($this->nextPollAt)($environment))->toBe('2026-09-17 10:00:15');
    });

    test('a longer or unchanged interval, or an environment never read, keeps it', function () {
        $longer = ($this->edited)(['poll_interval_seconds' => 15], '2026-09-17 10:00:10');
        $same = ($this->edited)(['name' => 'staging', 'poll_interval_seconds' => 60], '2026-09-17 10:00:50');
        $never = ($this->edited)(['name' => 'develop', 'poll_interval_seconds' => 300], null);

        ($this->save)($longer, ['pollIntervalSeconds' => 60]);
        ($this->save)($same, ['name' => 'staging', 'pollIntervalSeconds' => 60]);
        ($this->save)($never, ['name' => 'develop', 'pollIntervalSeconds' => 15]);

        expect(($this->nextPollAt)($longer))->toBe('2026-09-17 10:00:10')
            ->and(($this->nextPollAt)($same))->toBe('2026-09-17 10:00:50')
            ->and(($this->nextPollAt)($never))->toBeNull();
    });

    test('resuming collection makes it due at once, pausing leaves it alone', function () {
        $resumed = ($this->edited)(['polling_enabled' => false, 'poll_interval_seconds' => 300], '2026-09-17 10:03:00');
        $paused = ($this->edited)(['name' => 'staging', 'poll_interval_seconds' => 300], '2026-09-17 10:03:00');

        ($this->save)($resumed, ['pollIntervalSeconds' => 300, 'pollingEnabled' => true]);
        ($this->save)($paused, ['name' => 'staging', 'pollIntervalSeconds' => 300, 'pollingEnabled' => false]);

        expect(($this->nextPollAt)($resumed))->toBe('2026-09-17 10:00:00')
            ->and(($this->nextPollAt)($paused))->toBe('2026-09-17 10:03:00');
    });

    test('a new Horizon address forgets the shown state and is read at once, keeping the history', function (string $url) {
        $environment = ($this->edited)(['poll_interval_seconds' => 300], '2026-09-17 10:03:00');
        $other = ($this->edited)(['name' => 'staging'], '2026-09-17 10:03:00');
        EnvironmentState::factory()->for($environment)->create();
        EnvironmentState::factory()->for($other)->create();
        EnvironmentSnapshot::factory()->for($environment)->create();

        ($this->save)($environment, ['horizonUrl' => $url, 'basicAuthPassword' => 'super-secret-value', 'pollIntervalSeconds' => 300]);

        expect(EnvironmentState::query()->where('environment_id', $environment->id)->exists())->toBeFalse()
            ->and(EnvironmentState::query()->where('environment_id', $other->id)->exists())->toBeTrue()
            ->and(EnvironmentSnapshot::query()->where('environment_id', $environment->id)->count())->toBe(1)
            ->and(($this->nextPollAt)($environment))->toBe('2026-09-17 10:00:00')
            ->and(($this->nextPollAt)($other))->toBe('2026-09-17 10:03:00');
    })->with([
        'host' => 'https://preprod.example.com/horizon',
        'scheme' => 'http://production.example.com/horizon',
        'port' => 'https://production.example.com:8443/horizon',
        'path' => 'https://production.example.com/admin/horizon',
    ]);

    test('the same address written differently keeps the state', function (string $url) {
        $environment = ($this->edited)(['poll_interval_seconds' => 300], '2026-09-17 10:03:00');
        EnvironmentState::factory()->for($environment)->create();

        ($this->save)($environment, ['horizonUrl' => $url, 'pollIntervalSeconds' => 300]);

        expect(EnvironmentState::query()->where('environment_id', $environment->id)->exists())->toBeTrue()
            ->and(($this->nextPollAt)($environment))->toBe('2026-09-17 10:03:00');
    })->with([
        'unchanged' => 'https://production.example.com/horizon',
        'trailing slash' => 'https://production.example.com/horizon/',
        'api suffix' => 'https://production.example.com/horizon/api',
        'host case and default port' => 'HTTPS://Production.Example.com:443/horizon',
    ]);
});
