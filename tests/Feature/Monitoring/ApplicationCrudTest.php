<?php

use App\Enums\EnvironmentColor;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Team;
use App\Models\User;
use App\Policies\EnvironmentPolicy;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    $this->team = Team::factory()->create();

    $this->admin = User::factory()->create();
    $this->team->members()->attach($this->admin, ['role' => TeamRole::Admin->value]);

    $this->member = User::factory()->create();
    $this->team->members()->attach($this->member, ['role' => TeamRole::Member->value]);

    $this->viewer = User::factory()->create();
    $this->team->members()->attach($this->viewer, ['role' => TeamRole::Viewer->value]);

    $this->application = Application::factory()->for($this->team)->create(['name' => 'Fatturaomatic']);

    // A minimal valid environment payload, local to this file's $this so it
    // never risks colliding with a same-named global helper from another
    // lane's test file loaded in the same run.
    $this->environmentPayload = fn (string $name = 'production'): array => [
        'name' => $name,
        'color' => EnvironmentColor::Prod->value,
        'horizonUrl' => 'https://'.$name.'.invoicer.example.com/horizon/api',
        'basicAuthUser' => null,
        'basicAuthPassword' => null,
        'pollIntervalSeconds' => 15,
    ];
});

test('an admin creates an application with two environments in one request', function () {
    $response = $this->actingAs($this->admin)->post(route('applications.store', ['current_team' => $this->team->slug]), [
        'application' => [
            'name' => 'Invoicer',
            'host' => 'invoicer.example.com',
        ],
        'environments' => [
            ($this->environmentPayload)('production'),
            ($this->environmentPayload)('staging'),
        ],
    ]);

    $response->assertRedirect();

    $application = Application::where('team_id', $this->team->id)->where('name', 'Invoicer')->firstOrFail();

    expect($application->slug)->toBe('invoicer')
        ->and($application->environments()->count())->toBe(2)
        ->and($application->environments()->orderBy('id')->pluck('slug')->all())
        ->toBe(['invoicer-production', 'invoicer-staging']);
});

test('the wizard consults the manage-credentials permission for the environments it creates', function () {
    // Same policy swap as EnvironmentCrudTest: today's matrix grants
    // manageCredentials to the same roles as ManageApplications, so forcing
    // the denial is the only way to prove the wizard consults the second
    // gate at all instead of relying on the two staying identical.
    Gate::policy(Environment::class, get_class(new class extends EnvironmentPolicy
    {
        public function manageCredentials(User $user, Environment $environment): bool
        {
            return false;
        }
    }));

    // No credentials anywhere in the payload: the gate is never consulted.
    $this->actingAs($this->admin)->post(route('applications.store', ['current_team' => $this->team->slug]), [
        'application' => ['name' => 'Plain', 'host' => 'plain.example.com'],
        'environments' => [($this->environmentPayload)()],
    ])->assertRedirect();

    // One environment out of two carries a username: the whole request is
    // refused and nothing is written.
    $this->actingAs($this->admin)->post(route('applications.store', ['current_team' => $this->team->slug]), [
        'application' => ['name' => 'Guarded', 'host' => 'guarded.example.com'],
        'environments' => [
            ($this->environmentPayload)('production'),
            [...($this->environmentPayload)('staging'), 'basicAuthUser' => 'monitor', 'basicAuthPassword' => 'secret'],
        ],
    ])->assertForbidden();

    expect(Application::where('team_id', $this->team->id)->where('name', 'Guarded')->exists())->toBeFalse();
});

test('the created application belongs to the organization it was created in', function () {
    $otherTeam = Team::factory()->create();

    $this->actingAs($this->admin)->post(route('applications.store', ['current_team' => $this->team->slug]), [
        'application' => ['name' => 'Scoped', 'host' => 'scoped.example.com'],
        'environments' => [($this->environmentPayload)()],
    ])->assertRedirect();

    $application = Application::where('name', 'Scoped')->firstOrFail();

    expect($application->team_id)->toBe($this->team->id)
        ->and(Application::where('team_id', $otherTeam->id)->where('name', 'Scoped')->exists())->toBeFalse();
});

test('member and viewer cannot create, edit or delete applications', function (string $role) {
    $user = $role === 'member' ? $this->member : $this->viewer;

    $this->actingAs($user)
        ->get(route('applications.create', ['current_team' => $this->team->slug]))
        ->assertForbidden();

    $this->actingAs($user)
        ->post(route('applications.store', ['current_team' => $this->team->slug]), [
            'application' => ['name' => 'Blocked', 'host' => 'blocked.example.com'],
            'environments' => [($this->environmentPayload)()],
        ])
        ->assertForbidden();

    $this->actingAs($user)
        ->get(route('applications.edit', ['current_team' => $this->team->slug, 'application' => $this->application->slug]))
        ->assertForbidden();

    $this->actingAs($user)
        ->patch(route('applications.update', ['current_team' => $this->team->slug, 'application' => $this->application->slug]), [
            'name' => 'Renamed',
            'host' => $this->application->host,
        ])
        ->assertForbidden();

    $this->actingAs($user)
        ->delete(route('applications.destroy', ['current_team' => $this->team->slug, 'application' => $this->application->slug]), [
            'name' => $this->application->name,
        ])
        ->assertForbidden();

    expect(Application::where('name', 'Blocked')->exists())->toBeFalse();
})->with(['member', 'viewer']);

test('the wizard is rejected without at least one environment', function () {
    $this->actingAs($this->admin)
        ->post(route('applications.store', ['current_team' => $this->team->slug]), [
            'application' => ['name' => 'NoEnv', 'host' => 'noenv.example.com'],
            'environments' => [],
        ])
        ->assertInvalid(['environments']);

    expect(Application::where('name', 'NoEnv')->exists())->toBeFalse();
});

test('the wizard is rejected when two rows carry the same environment name', function () {
    $this->actingAs($this->admin)
        ->post(route('applications.store', ['current_team' => $this->team->slug]), [
            'application' => ['name' => 'Twice', 'host' => 'twice.example.com'],
            'environments' => [
                ($this->environmentPayload)('production'),
                ($this->environmentPayload)('production'),
            ],
        ])
        // On the row, not on "environments": the wizard renders the message
        // under the offending field, on the step that owns it.
        ->assertInvalid(['environments.1.name']);

    expect(Application::where('name', 'Twice')->exists())->toBeFalse();
});

test('the wizard accepts two rows that only differ by name', function () {
    $this->actingAs($this->admin)
        ->post(route('applications.store', ['current_team' => $this->team->slug]), [
            'application' => ['name' => 'Pair', 'host' => 'pair.example.com'],
            'environments' => [
                ($this->environmentPayload)('production'),
                ($this->environmentPayload)('preprod'),
            ],
        ])
        ->assertRedirect();

    expect(Application::where('name', 'Pair')->firstOrFail()->environments()->count())->toBe(2);
});

test('a wizard row with a password but no username is rejected on that row', function () {
    $this->actingAs($this->admin)
        ->post(route('applications.store', ['current_team' => $this->team->slug]), [
            'application' => ['name' => 'Orphan', 'host' => 'orphan.example.com'],
            'environments' => [
                [...($this->environmentPayload)('production'), 'basicAuthPassword' => 'orphan-secret'],
            ],
        ])
        // Nested: the rule has to name "environments.0.basicAuthPassword",
        // not a top-level field that does not exist in this payload.
        ->assertInvalid(['environments.0.basicAuthUser']);

    expect(Application::where('name', 'Orphan')->exists())->toBeFalse();
});

test('renaming an application does not change its slug', function () {
    $originalSlug = $this->application->slug;

    $this->actingAs($this->admin)
        ->patch(route('applications.update', ['current_team' => $this->team->slug, 'application' => $this->application->slug]), [
            'name' => 'Fatturaomatic Renamed',
            'host' => $this->application->host,
        ])
        ->assertRedirect();

    $this->application->refresh();

    expect($this->application->name)->toBe('Fatturaomatic Renamed')
        ->and($this->application->slug)->toBe($originalSlug);
});

test('deleting an application requires typing its exact name, and cascades to its environments', function () {
    $environment = Environment::factory()->for($this->application)->create();

    $this->actingAs($this->admin)
        ->delete(route('applications.destroy', ['current_team' => $this->team->slug, 'application' => $this->application->slug]), [
            'name' => 'wrong name',
        ])
        ->assertInvalid(['name']);

    expect(Application::find($this->application->id))->not->toBeNull()
        ->and(Environment::find($environment->id))->not->toBeNull();

    $this->actingAs($this->admin)
        ->delete(route('applications.destroy', ['current_team' => $this->team->slug, 'application' => $this->application->slug]), [
            'name' => $this->application->name,
        ])
        ->assertRedirect();

    expect(Application::find($this->application->id))->toBeNull()
        ->and(Environment::find($environment->id))->toBeNull();
});

test('an application from another organization responds 404', function () {
    $otherTeam = Team::factory()->create();
    $foreignApplication = Application::factory()->for($otherTeam)->create();

    $this->actingAs($this->admin)
        ->get(route('applications.edit', ['current_team' => $this->team->slug, 'application' => $foreignApplication->slug]))
        ->assertNotFound();

    $this->actingAs($this->admin)
        ->patch(route('applications.update', ['current_team' => $this->team->slug, 'application' => $foreignApplication->slug]), [
            'name' => 'Hijacked',
            'host' => 'hijacked.example.com',
        ])
        ->assertNotFound();

    $this->actingAs($this->admin)
        ->delete(route('applications.destroy', ['current_team' => $this->team->slug, 'application' => $foreignApplication->slug]), [
            'name' => $foreignApplication->name,
        ])
        ->assertNotFound();

    expect(Application::find($foreignApplication->id))->not->toBeNull();
});

test('two organizations sharing the same application slug each resolve their own', function () {
    $otherTeam = Team::factory()->create();
    $otherAdmin = User::factory()->create();
    $otherTeam->members()->attach($otherAdmin, ['role' => TeamRole::Admin->value]);
    // Same name as $this->application, in a different organization:
    // slugs are only unique per team (unique(['team_id', 'slug'])), so
    // this legitimately produces the same slug in both organizations.
    $otherApplication = Application::factory()->for($otherTeam)->create(['name' => $this->application->name]);

    expect($otherApplication->slug)->toBe($this->application->slug);

    $this->actingAs($this->admin)
        ->get(route('applications.edit', ['current_team' => $this->team->slug, 'application' => $this->application->slug]))
        ->assertOk();

    $this->actingAs($otherAdmin)
        ->get(route('applications.edit', ['current_team' => $otherTeam->slug, 'application' => $otherApplication->slug]))
        ->assertOk();
});
