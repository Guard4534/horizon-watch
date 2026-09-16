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
