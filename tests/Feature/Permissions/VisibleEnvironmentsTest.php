<?php

use App\Enums\MemberVisibility;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Team;
use App\Models\User;
use App\Monitoring\VisibleEnvironments;

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
    $this->userManual->teamMemberships()->where('team_id', $this->team->id)->first()
        ->visibleEnvironments()->attach([$this->alphaProduction->id, $this->charlieTesting->id]);

    // Another organization, with an environment of the same name, to prove
    // it never leaks in regardless of visibility.
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

test('allows matches the query for all', function () {
    expect($this->filter->allows($this->team, $this->userAll, $this->alphaProduction))->toBeTrue()
        ->and($this->filter->allows($this->team, $this->userAll, $this->charlieTesting))->toBeTrue()
        ->and($this->filter->allows($this->team, $this->userAll, $this->foreignEnvironment))->toBeFalse();
});

test('allows matches the query for non_production', function () {
    expect($this->filter->allows($this->team, $this->userNonProduction, $this->alphaStaging))->toBeTrue()
        ->and($this->filter->allows($this->team, $this->userNonProduction, $this->alphaProduction))->toBeFalse()
        ->and($this->filter->allows($this->team, $this->userNonProduction, $this->bravoProduction))->toBeFalse()
        ->and($this->filter->allows($this->team, $this->userNonProduction, $this->foreignEnvironment))->toBeFalse();
});

test('allows matches the query for manual', function () {
    expect($this->filter->allows($this->team, $this->userManual, $this->alphaProduction))->toBeTrue()
        ->and($this->filter->allows($this->team, $this->userManual, $this->charlieTesting))->toBeTrue()
        ->and($this->filter->allows($this->team, $this->userManual, $this->alphaStaging))->toBeFalse()
        ->and($this->filter->allows($this->team, $this->userManual, $this->bravoProduction))->toBeFalse()
        ->and($this->filter->allows($this->team, $this->userManual, $this->foreignEnvironment))->toBeFalse();
});
