<?php

use App\Enums\EnvironmentType;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Team;
use App\Models\User;
use App\Policies\EnvironmentPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Grants;

beforeEach(function () {
    $this->team = Team::factory()->create();

    $this->admin = User::factory()->create();
    $this->team->members()->attach($this->admin, ['role' => TeamRole::Admin->value]);

    $this->member = User::factory()->create();
    $this->team->members()->attach($this->member, ['role' => TeamRole::Member->value]);

    $this->viewer = User::factory()->create();
    $this->team->members()->attach($this->viewer, ['role' => TeamRole::Viewer->value]);

    $this->application = Application::factory()->for($this->team)->create(['name' => 'Invoice Desk']);

    $this->environmentPayload = fn (string $name = 'production'): array => [
        'name' => $name,
        'type' => (EnvironmentType::suggestFor($name) ?? EnvironmentType::Staging)->value,
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
    Gate::policy(Environment::class, get_class(new class extends EnvironmentPolicy
    {
        public function manageCredentials(User $user, Environment $environment): bool
        {
            return false;
        }
    }));

    $this->actingAs($this->admin)->post(route('applications.store', ['current_team' => $this->team->slug]), [
        'application' => ['name' => 'Plain'],
        'environments' => [($this->environmentPayload)()],
    ])->assertRedirect();

    $this->actingAs($this->admin)->post(route('applications.store', ['current_team' => $this->team->slug]), [
        'application' => ['name' => 'Guarded'],
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
        'application' => ['name' => 'Scoped'],
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
            'application' => ['name' => 'Blocked'],
            'environments' => [($this->environmentPayload)()],
        ])
        ->assertForbidden();

    $this->actingAs($user)
        ->get(route('applications.edit', ['current_team' => $this->team->slug, 'application' => $this->application->slug]))
        ->assertForbidden();

    $this->actingAs($user)
        ->patch(route('applications.update', ['current_team' => $this->team->slug, 'application' => $this->application->slug]), [
            'name' => 'Renamed',
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
            'application' => ['name' => 'NoEnv'],
            'environments' => [],
        ])
        ->assertInvalid(['environments']);

    expect(Application::where('name', 'NoEnv')->exists())->toBeFalse();
});

test('the wizard is rejected past the environment cap, which the form knows', function () {
    config(['horizon-watch.limits.wizard_environments' => 2]);

    $this->actingAs($this->admin)
        ->get(route('applications.create', ['current_team' => $this->team->slug]))
        ->assertInertia(fn (Assert $page) => $page->where('page.maxEnvironments', 2));

    $this->actingAs($this->admin)
        ->post(route('applications.store', ['current_team' => $this->team->slug]), [
            'application' => ['name' => 'Crowded'],
            'environments' => [
                ($this->environmentPayload)('production'),
                ($this->environmentPayload)('staging'),
                ($this->environmentPayload)('develop'),
            ],
        ])
        ->assertInvalid(['environments']);

    expect(Application::where('name', 'Crowded')->exists())->toBeFalse();
});

test('the wizard is rejected when two rows carry the same environment name', function () {
    $this->actingAs($this->admin)
        ->post(route('applications.store', ['current_team' => $this->team->slug]), [
            'application' => ['name' => 'Twice'],
            'environments' => [
                ($this->environmentPayload)('production'),
                ($this->environmentPayload)('production'),
            ],
        ])
        ->assertInvalid(['environments.1.name']);

    expect(Application::where('name', 'Twice')->exists())->toBeFalse();
});

test('the wizard accepts two rows that only differ by name', function () {
    $this->actingAs($this->admin)
        ->post(route('applications.store', ['current_team' => $this->team->slug]), [
            'application' => ['name' => 'Pair'],
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
            'application' => ['name' => 'Orphan'],
            'environments' => [
                [...($this->environmentPayload)('production'), 'basicAuthPassword' => 'orphan-secret'],
            ],
        ])
        ->assertInvalid(['environments.0.basicAuthUser']);

    expect(Application::where('name', 'Orphan')->exists())->toBeFalse();
});

test('a failed wizard does not flash any row password into the session', function () {
    $this->actingAs($this->admin)
        ->post(route('applications.store', ['current_team' => $this->team->slug]), [
            'application' => ['name' => 'Flashed'],
            'environments' => [
                [
                    ...($this->environmentPayload)('production'),
                    'basicAuthUser' => 'monitor',
                    'basicAuthPassword' => 'first-must-not-persist',
                ],
                [
                    ...($this->environmentPayload)('production'),
                    'basicAuthUser' => 'monitor',
                    'basicAuthPassword' => 'second-must-not-persist',
                ],
            ],
        ])
        ->assertInvalid(['environments.1.name']);

    expect(json_encode(session()->all(), JSON_THROW_ON_ERROR))
        ->not->toContain('first-must-not-persist')
        ->not->toContain('second-must-not-persist');
});

test('renaming an application does not change its slug', function () {
    $originalSlug = $this->application->slug;

    $this->actingAs($this->admin)
        ->patch(route('applications.update', ['current_team' => $this->team->slug, 'application' => $this->application->slug]), [
            'name' => 'Invoice Desk Renamed',
        ])
        ->assertRedirect();

    $this->application->refresh();

    expect($this->application->name)->toBe('Invoice Desk Renamed')
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
    $otherApplication = Application::factory()->for($otherTeam)->create(['name' => $this->application->name]);

    expect($otherApplication->slug)->toBe($this->application->slug);

    $this->actingAs($this->admin)
        ->get(route('applications.edit', ['current_team' => $this->team->slug, 'application' => $this->application->slug]))
        ->assertOk();

    $this->actingAs($otherAdmin)
        ->get(route('applications.edit', ['current_team' => $otherTeam->slug, 'application' => $otherApplication->slug]))
        ->assertOk();
});

test('deleting an application clears the manual visibility grants of its environments', function () {
    $environment = Environment::factory()->for($this->application)->create();

    Grants::give($this->member->id, $environment->id);

    expect(DB::table('environment_user')->where('environment_id', $environment->id)->exists())->toBeTrue();

    $this->actingAs($this->admin)
        ->delete(route('applications.destroy', ['current_team' => $this->team->slug, 'application' => $this->application->slug]), [
            'name' => $this->application->name,
        ])
        ->assertRedirect();

    expect(DB::table('environment_user')->where('environment_id', $environment->id)->exists())->toBeFalse()
        ->and(DB::table('environment_user')->where('user_id', $this->member->id)->exists())->toBeFalse();
});

test('the wizard stores each row\'s collection switch, on by default', function () {
    $this->actingAs($this->admin)->post(route('applications.store', ['current_team' => $this->team->slug]), [
        'application' => ['name' => 'Switches'],
        'environments' => [
            ($this->environmentPayload)('production'),
            [...($this->environmentPayload)('staging'), 'pollingEnabled' => false],
        ],
    ])->assertRedirect();

    $application = Application::where('team_id', $this->team->id)->where('name', 'Switches')->firstOrFail();

    expect($application->environments()->orderBy('name')->pluck('polling_enabled', 'name')->all())
        ->toBe(['production' => true, 'staging' => false]);
});

test('the wizard stores each row\'s type', function () {
    $this->actingAs($this->admin)->post(route('applications.store', ['current_team' => $this->team->slug]), [
        'application' => ['name' => 'Types'],
        'environments' => [
            ($this->environmentPayload)('prod'),
            [...($this->environmentPayload)('blue'), 'type' => EnvironmentType::Production->value],
            [...($this->environmentPayload)('production'), 'type' => EnvironmentType::Demo->value],
            ($this->environmentPayload)('staging'),
        ],
    ])->assertRedirect();

    $application = Application::where('team_id', $this->team->id)->where('name', 'Types')->firstOrFail();

    expect($application->environments()->orderBy('name')->pluck('type', 'name')->map(fn (EnvironmentType $type) => $type->value)->all())
        ->toBe(['blue' => 'production', 'prod' => 'production', 'production' => 'demo', 'staging' => 'staging']);
});

test('a wizard row whose URL carries credentials is refused on that row', function () {
    $this->actingAs($this->admin)
        ->post(route('applications.store', ['current_team' => $this->team->slug]), [
            'application' => ['name' => 'Leaky'],
            'environments' => [
                ($this->environmentPayload)('production'),
                [...($this->environmentPayload)('staging'), 'horizonUrl' => 'https://ops:url-secret@staging.leaky.example.com/horizon'],
            ],
        ])
        ->assertInvalid(['environments.1.horizonUrl' => 'basic-auth fields'])
        ->assertValid(['environments.0.horizonUrl']);

    expect(Application::where('name', 'Leaky')->exists())->toBeFalse()
        ->and(json_encode(session()->all(), JSON_THROW_ON_ERROR))->not->toContain('url-secret');
});

test('the wizard keeps no domain and stores each row\'s URL tidied', function () {
    $this->actingAs($this->admin)->post(route('applications.store', ['current_team' => $this->team->slug]), [
        'application' => ['name' => 'Tidy', 'host' => 'ignored.example.com'],
        'environments' => [
            [...($this->environmentPayload)('production'), 'horizonUrl' => 'HTTPS://Production.Tidy.example.com/horizon/api/'],
        ],
    ])->assertRedirect();

    expect(Schema::hasColumn('applications', 'host'))->toBeFalse()
        ->and(Application::where('name', 'Tidy')->sole()->environments()->sole()->horizon_url)
        ->toBe('https://production.tidy.example.com/horizon');
});

test('the wizard rows call the field Horizon URL in their rule messages too', function () {
    $this->actingAs($this->admin)->post(route('applications.store', ['current_team' => $this->team->slug]), [
        'application' => ['name' => 'Named'],
        'environments' => [[...($this->environmentPayload)('production'), 'horizonUrl' => '']],
    ])->assertInvalid(['environments.0.horizonUrl' => 'The Horizon URL field is required.']);
});

test('creating an application lands on its page', function () {
    $this->actingAs($this->admin)->post(route('applications.store', ['current_team' => $this->team->slug]), [
        'application' => ['name' => 'Ledger'],
        'environments' => [($this->environmentPayload)('production')],
    ])->assertRedirect(route('applications.show', ['current_team' => $this->team->slug, 'application' => 'ledger']));
});
