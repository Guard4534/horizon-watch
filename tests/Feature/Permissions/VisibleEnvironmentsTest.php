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

/**
 * The three tests below used to go through a VisibleEnvironments::allows()
 * helper, deleted with the memoized slug map that replaced it: one
 * exists() per environment invites a caller to ask per row. They now ask
 * query() the same questions — in particular the one the per-visibility
 * tests above do not: that another organization's environment never
 * arrives, whatever the visibility.
 */
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

/**
 * The unfiltered escape hatch of the Applications view (see
 * VisibleEnvironments::ofTeam()): no visibility filter, and still no way
 * into another organization.
 */
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
