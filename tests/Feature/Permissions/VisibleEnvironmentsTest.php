<?php

use App\Enums\MemberVisibility;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Team;
use App\Models\User;
use App\Monitoring\VisibleEnvironments;
use Tests\Support\Grants;

beforeEach(function () {
    $this->filter = new VisibleEnvironments;

    $this->team = Team::factory()->create();

    $this->appAlpha = Application::factory()->for($this->team)->create(['name' => 'Alpha']);
    $this->appBravo = Application::factory()->for($this->team)->create(['name' => 'Bravo']);
    $this->appCharlie = Application::factory()->for($this->team)->create(['name' => 'Charlie']);

    $this->alphaProduction = Environment::factory()->for($this->appAlpha)->production()->create();
    $this->alphaStaging = Environment::factory()->for($this->appAlpha)->staging()->create();
    $this->bravoProduction = Environment::factory()->for($this->appBravo)->production()->create();
    $this->bravoPreprod = Environment::factory()->for($this->appBravo)->preprod()->create();
    $this->charlieStaging = Environment::factory()->for($this->appCharlie)->staging()->create();
    $this->charlieTesting = Environment::factory()->for($this->appCharlie)->testing()->create();

    $this->userAll = User::factory()->create();
    $this->team->members()->attach($this->userAll, [
        'role' => TeamRole::Member->value,
        'visibility' => MemberVisibility::All->value,
    ]);

    $this->userNonProduction = User::factory()->create();
    $this->team->members()->attach($this->userNonProduction, [
        'role' => TeamRole::Member->value,
        'visibility' => MemberVisibility::NonProduction->value,
    ]);

    $this->userManual = User::factory()->create();
    $this->team->members()->attach($this->userManual, [
        'role' => TeamRole::Member->value,
        'visibility' => MemberVisibility::Manual->value,
    ]);
    Grants::give($this->userManual->id, $this->alphaProduction->id, $this->charlieTesting->id);

    $otherTeam = Team::factory()->create();
    $otherApplication = Application::factory()->for($otherTeam)->create();
    $this->foreignEnvironment = Environment::factory()->for($otherApplication)->production()->create();
});

test('all sees every environment of the organization, ordered by application then environment name', function () {
    $ids = $this->filter->query($this->team, $this->userAll)->pluck('id')->all();

    expect($ids)->toBe([
        $this->alphaProduction->id,
        $this->alphaStaging->id,
        $this->bravoPreprod->id,
        $this->bravoProduction->id,
        $this->charlieStaging->id,
        $this->charlieTesting->id,
    ]);
});

test('non_production excludes every environment named production', function () {
    $ids = $this->filter->query($this->team, $this->userNonProduction)->pluck('id')->all();

    expect($ids)->toEqualCanonicalizing([
        $this->alphaStaging->id,
        $this->bravoPreprod->id,
        $this->charlieStaging->id,
        $this->charlieTesting->id,
    ])
        ->and($ids)->not->toContain($this->alphaProduction->id)
        ->and($ids)->not->toContain($this->bravoProduction->id);
});

test('manual only sees the environments explicitly granted', function () {
    $ids = $this->filter->query($this->team, $this->userManual)->pluck('id')->all();

    expect($ids)->toEqualCanonicalizing([
        $this->alphaProduction->id,
        $this->charlieTesting->id,
    ]);
});

test('a member with no membership on the team sees nothing', function () {
    $stranger = User::factory()->create();

    expect($this->filter->query($this->team, $stranger)->pluck('id')->all())->toBe([]);
});

test('the query eager loads the application', function () {
    $environment = $this->filter->query($this->team, $this->userAll)->first();

    expect($environment->relationLoaded('application'))->toBeTrue();
});

test('the query decides one environment the same way for all', function () {
    $ids = $this->filter->query($this->team, $this->userAll)->pluck('id')->all();

    expect($ids)->toContain($this->alphaProduction->id)
        ->and($ids)->toContain($this->charlieTesting->id)
        ->and($ids)->not->toContain($this->foreignEnvironment->id);
});

test('the query decides one environment the same way for non_production', function () {
    $ids = $this->filter->query($this->team, $this->userNonProduction)->pluck('id')->all();

    expect($ids)->toContain($this->alphaStaging->id)
        ->and($ids)->not->toContain($this->alphaProduction->id)
        ->and($ids)->not->toContain($this->bravoProduction->id)
        ->and($ids)->not->toContain($this->foreignEnvironment->id);
});

test('the query decides one environment the same way for manual', function () {
    $ids = $this->filter->query($this->team, $this->userManual)->pluck('id')->all();

    expect($ids)->toContain($this->alphaProduction->id)
        ->and($ids)->toContain($this->charlieTesting->id)
        ->and($ids)->not->toContain($this->alphaStaging->id)
        ->and($ids)->not->toContain($this->bravoProduction->id)
        ->and($ids)->not->toContain($this->foreignEnvironment->id);
});

test('ofTeam returns the whole organization, and nothing outside it', function () {
    $ids = $this->filter->ofTeam($this->team)->pluck('id')->all();

    expect($ids)->toBe([
        $this->alphaProduction->id,
        $this->alphaStaging->id,
        $this->bravoPreprod->id,
        $this->bravoProduction->id,
        $this->charlieStaging->id,
        $this->charlieTesting->id,
    ])
        ->and($ids)->not->toContain($this->foreignEnvironment->id);
});

test('only a member who sees everything sees the alerts of a deleted environment', function () {
    $stranger = User::factory()->create();

    expect($this->filter->seesEverything($this->team, $this->userAll))->toBeTrue()
        ->and($this->filter->seesEverything($this->team, $this->userNonProduction))->toBeFalse()
        ->and($this->filter->seesEverything($this->team, $this->userManual))->toBeFalse()
        ->and($this->filter->seesEverything($this->team, $stranger))->toBeFalse()
        ->and($this->filter->sees($this->team, $this->userAll, null))->toBeTrue()
        ->and($this->filter->sees($this->team, $this->userNonProduction, null))->toBeFalse()
        ->and($this->filter->sees($this->team, $stranger, null))->toBeFalse();
});

test('sees decides one environment as the query does', function (string $user, string $environment, bool $sees) {
    expect($this->filter->sees($this->team, $this->{$user}, $this->{$environment}->id))->toBe($sees);
})->with([
    'all, production' => ['userAll', 'alphaProduction', true],
    'all, another organization' => ['userAll', 'foreignEnvironment', false],
    'non production, production' => ['userNonProduction', 'bravoProduction', false],
    'non production, staging' => ['userNonProduction', 'alphaStaging', true],
    'manual, granted' => ['userManual', 'charlieTesting', true],
    'manual, not granted' => ['userManual', 'alphaStaging', false],
]);

test('the ids of a membership are null for everything, or the environments it sees', function () {
    $membership = fn (User $user) => $user->teamMemberships()->where('team_id', $this->team->id)->sole();

    expect($this->filter->idsFor($this->team, $membership($this->userAll)))->toBeNull()
        ->and($this->filter->idsFor($this->team, $membership($this->userNonProduction)))->toEqualCanonicalizing([
            $this->alphaStaging->id,
            $this->bravoPreprod->id,
            $this->charlieStaging->id,
            $this->charlieTesting->id,
        ])
        ->and($this->filter->idsFor($this->team, $membership($this->userManual)))->toEqualCanonicalizing([
            $this->alphaProduction->id,
            $this->charlieTesting->id,
        ]);
});
