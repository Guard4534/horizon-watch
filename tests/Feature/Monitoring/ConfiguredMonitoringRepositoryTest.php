<?php

use App\Data\Monitoring\NotificationSettingsData;
use App\Enums\AlertState;
use App\Enums\MemberVisibility;
use App\Enums\RuleOrigin;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Team;
use App\Models\User;
use App\Monitoring\ConfiguredMonitoringRepository;
use App\Monitoring\MonitoringRepository;
use App\Queries\WallQuery;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::createFromTimestampUTC(1_789_000_020));
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user, [
        'role' => TeamRole::Admin->value,
        'visibility' => MemberVisibility::All->value,
    ]);
    $this->actingAs($this->user);

    $this->repository = app(MonitoringRepository::class);
});

test('the container resolves the configured repository', function () {
    expect($this->repository)->toBeInstanceOf(ConfiguredMonitoringRepository::class);
});

test('applications and environments come from the database, ordered as phase 1', function () {
    $alpha = Application::factory()->for($this->team)->create(['name' => 'Alpha']);
    $bravo = Application::factory()->for($this->team)->create(['name' => 'Bravo']);
    $charlie = Application::factory()->for($this->team)->create(['name' => 'Charlie']);

    $alphaProduction = Environment::factory()->for($alpha)->production()->create();
    $alphaStaging = Environment::factory()->for($alpha)->staging()->create();
    $bravoProduction = Environment::factory()->for($bravo)->production()->create();
    $bravoStaging = Environment::factory()->for($bravo)->staging()->create();
    $charlieProduction = Environment::factory()->for($charlie)->production()->create();
    $charlieStaging = Environment::factory()->for($charlie)->staging()->create();

    expect(array_map(fn ($application) => $application->id, $this->repository->applications($this->team)))
        ->toBe([$alpha->slug, $bravo->slug, $charlie->slug]);

    expect(array_map(fn ($environment) => $environment->id, $this->repository->environments($this->team)))
        ->toBe([
            $alphaProduction->slug, $alphaStaging->slug,
            $bravoProduction->slug, $bravoStaging->slug,
            $charlieProduction->slug, $charlieStaging->slug,
        ]);

    // Unrestricted: every row of both views is watched.
    expect(collect($this->repository->environments($this->team))->every->watched)->toBeTrue()
        ->and(collect($this->repository->configurableEnvironments($this->team))->every->watched)->toBeTrue();

    expect($this->repository->environment($this->team, $bravoStaging->slug)?->id)->toBe($bravoStaging->slug)
        ->and($this->repository->configurableApplication($this->team, $bravo->slug)?->id)->toBe($bravo->slug);
});

test('an environment of another organization does not appear', function () {
    $application = Application::factory()->for($this->team)->create();
    Environment::factory()->for($application)->production()->create();

    $other = Team::factory()->create();
    $otherApplication = Application::factory()->for($other)->create();
    $foreign = Environment::factory()->for($otherApplication)->production()->create();

    expect($this->repository->environment($this->team, $foreign->slug))->toBeNull()
        ->and($this->repository->environments($this->team))->toHaveCount(1)
        ->and($this->repository->nodes($this->team, $foreign->slug))->toBe([]);

    // The Applications view drops the visibility filter, never the
    // organization: $this->user is an admin, so this is the unfiltered
    // branch answering.
    expect($this->repository->configurableEnvironments($this->team))->toHaveCount(1)
        ->and($this->repository->configurableApplications($this->team))->toHaveCount(1)
        ->and($this->repository->configurableApplication($this->team, $otherApplication->slug))->toBeNull();
});

test('an application with no visible environment does not appear, even though it has one', function () {
    $alpha = Application::factory()->for($this->team)->create(['name' => 'Alpha']);
    $bravo = Application::factory()->for($this->team)->create(['name' => 'Bravo']);
    $charlie = Application::factory()->for($this->team)->create(['name' => 'Charlie']);

    $alphaProduction = Environment::factory()->for($alpha)->production()->create();
    Environment::factory()->for($bravo)->production()->create();
    Environment::factory()->for($charlie)->production()->create();

    $membership = $this->user->teamMemberships()->where('team_id', $this->team->id)->first();
    $membership->update(['visibility' => MemberVisibility::Manual->value]);
    $membership->visibleEnvironments()->attach([$alphaProduction->id]);

    $applications = $this->repository->applications($this->team);

    // The watched view: the wall and its counts. The Applications view of
    // this same admin lists all three — see the split test below.
    expect($applications)->toHaveCount(1)
        ->and($applications[0]->id)->toBe($alpha->slug);

    // The wall's applicationCount goes through the same filtered list.
    expect(app(WallQuery::class)->handle($this->team)->applicationCount)->toBe(1);
});

test('an admin sees an application with zero environments, so they can still reach it', function () {
    // $this->user is Admin (see beforeEach): allowed to manage applications.
    $empty = Application::factory()->for($this->team)->create(['name' => 'Empty']);

    $applications = $this->repository->applications($this->team);

    expect($applications)->toHaveCount(1)
        ->and($applications[0]->id)->toBe($empty->slug)
        ->and($this->repository->configurableApplication($this->team, $empty->slug))->not->toBeNull();
});

test('a viewer does not see an application with zero environments', function () {
    $viewer = User::factory()->create();
    $this->team->members()->attach($viewer, [
        'role' => TeamRole::Viewer->value,
        'visibility' => MemberVisibility::All->value,
    ]);
    $empty = Application::factory()->for($this->team)->create(['name' => 'Empty']);

    $this->actingAs($viewer);
    $repository = app(MonitoringRepository::class);

    expect($repository->applications($this->team))->toBe([])
        ->and($repository->configurableApplications($this->team))->toBe([])
        ->and($repository->configurableApplication($this->team, $empty->slug))->toBeNull();
});

test('an application whose environments are all hidden still reaches the Applications view of an admin', function () {
    // The rare case the spec documents: "un admin con manual vede solo i
    // suoi ambienti, ma li configura tutti dalla vista Applicativi (dove
    // serve il permesso, non la visibilità)". $this->user is an Admin whose
    // manual visibility grants nothing.
    $application = Application::factory()->for($this->team)->create(['name' => 'Fatturaomatic']);
    $environment = Environment::factory()->for($application)->production()->create();

    $this->user->teamMemberships()->where('team_id', $this->team->id)->first()
        ->update(['visibility' => MemberVisibility::Manual->value]);

    $repository = app(MonitoringRepository::class);

    // The watched view stays empty: wall, alerts, scopes and counts.
    expect($repository->applications($this->team))->toBe([])
        ->and($repository->environments($this->team))->toBe([])
        ->and($repository->alerts($this->team, AlertState::Open))->toBe([])
        // …and so does the environment's own detail page, which is the
        // watched view too and must still answer 404.
        ->and($repository->environment($this->team, $environment->slug))->toBeNull();

    // The Applications view answers to the permission instead, so there is
    // a link to the environment whose credentials this admin may fix.
    expect($repository->configurableApplications($this->team))->toHaveCount(1)
        ->and($repository->configurableApplication($this->team, $application->slug))->not->toBeNull()
        ->and(array_map(fn ($item) => $item->id, $repository->configurableEnvironments($this->team)))
        ->toBe([$environment->slug])
        // Listed by the permission, not watched: the flag the Applications
        // templates key their links off.
        ->and($repository->configurableEnvironments($this->team)[0]->watched)->toBeFalse();
});

test('an application whose environments are all hidden stays hidden for a member', function () {
    // The mirror of the test above: without ManageApplications there is
    // nothing to configure, so the Applications view is filtered like every
    // other page — "un applicativo di cui non si vede nessun ambiente non
    // compare nell'elenco".
    $application = Application::factory()->for($this->team)->create(['name' => 'Fatturaomatic']);
    $environment = Environment::factory()->for($application)->production()->create();

    $member = User::factory()->create();
    $this->team->members()->attach($member, [
        'role' => TeamRole::Member->value,
        'visibility' => MemberVisibility::Manual->value,
    ]);

    $this->actingAs($member);
    $repository = app(MonitoringRepository::class);

    expect($repository->applications($this->team))->toBe([])
        ->and($repository->configurableApplications($this->team))->toBe([])
        ->and($repository->configurableApplication($this->team, $application->slug))->toBeNull()
        ->and($repository->configurableEnvironments($this->team))->toBe([])
        ->and($repository->environment($this->team, $environment->slug))->toBeNull();
});

test('non_production visibility hides production environments, including from alert counts', function () {
    // Fixed incident slug, so this environment is deterministically unhealthy.
    $application = Application::factory()->for($this->team)->create(['name' => 'Fatturaomatic']);
    Environment::factory()->for($application)->production()->create();
    $staging = Environment::factory()->for($application)->staging()->create();

    $openBefore = $this->repository->alerts($this->team, AlertState::Open);
    expect(collect($openBefore)->pluck('environmentId'))->toContain('fatturaomatic-production');

    $this->user->teamMemberships()->where('team_id', $this->team->id)->first()
        ->update(['visibility' => MemberVisibility::NonProduction->value]);

    // A fresh instance, as a new request would get: the repository memoizes
    // visible environments per instance ("the object lives for one
    // request"), so re-reading a membership change through the very same
    // instance is not a scenario a real request ever hits.
    $repository = app(MonitoringRepository::class);

    expect(array_map(fn ($environment) => $environment->id, $repository->environments($this->team)))
        ->toBe([$staging->slug]);

    $openAfter = $repository->alerts($this->team, AlertState::Open);
    expect(collect($openAfter)->pluck('environmentId'))->not->toContain('fatturaomatic-production');
});

test('the metrics stay identical within a tick and move on the next', function () {
    $application = Application::factory()->for($this->team)->create();
    Environment::factory()->for($application)->production()->create();

    $snapshot = fn () => array_map(fn ($environment) => $environment->toArray(), $this->repository->environments($this->team));
    $first = $snapshot();

    $this->travel(14)->seconds();
    expect($snapshot())->toBe($first);

    $this->travel(15)->seconds();
    expect($snapshot())->not->toBe($first);
});

test('with zero applications every method returns an empty result without error', function () {
    expect($this->repository->applications($this->team))->toBe([])
        ->and($this->repository->environments($this->team))->toBe([])
        ->and($this->repository->configurableApplications($this->team))->toBe([])
        ->and($this->repository->configurableEnvironments($this->team))->toBe([])
        ->and($this->repository->configurableApplication($this->team, 'anything'))->toBeNull()
        ->and($this->repository->environment($this->team, 'anything'))->toBeNull()
        ->and($this->repository->nodes($this->team, 'anything'))->toBe([])
        ->and($this->repository->queues($this->team, 'anything'))->toBe([])
        ->and($this->repository->failedJobs($this->team, 'anything'))->toBe([])
        ->and($this->repository->longRunningJobs($this->team, 'anything'))->toBe([])
        ->and($this->repository->alerts($this->team, AlertState::Open))->toBe([])
        ->and($this->repository->alerts($this->team, AlertState::Muted))->toBe([])
        ->and($this->repository->alerts($this->team, AlertState::Resolved))->toBe([])
        ->and($this->repository->alertRules($this->team, 'organization'))->toHaveCount(8)
        ->and($this->repository->notificationSettings($this->team))->toBeInstanceOf(NotificationSettingsData::class);

    $scopes = $this->repository->ruleScopes($this->team);
    expect($scopes)->toHaveCount(1)
        ->and($scopes[0]->id)->toBe('organization')
        ->and($scopes[0]->environmentCount)->toBe(0);
});

test('ruleScopes lists organization plus only the environment names present', function () {
    $application = Application::factory()->for($this->team)->create();
    Environment::factory()->for($application)->production()->create();
    Environment::factory()->for($application)->workerBatch()->create();

    $ids = array_map(fn ($scope) => $scope->id, $this->repository->ruleScopes($this->team));

    expect($ids)->toBe(['organization', 'production', 'worker-batch']);
});

test('overrides only apply to scopes that define them', function () {
    $application = Application::factory()->for($this->team)->create();
    Environment::factory()->for($application)->production()->create();
    Environment::factory()->for($application)->workerBatch()->create();

    $overridesOf = fn (string $scope) => count(array_filter(
        $this->repository->alertRules($this->team, $scope),
        fn ($rule) => $rule->origin === RuleOrigin::Override,
    ));

    expect($this->repository->alertRules($this->team, 'organization'))->toHaveCount(8)
        ->and($overridesOf('organization'))->toBe(0)
        ->and($overridesOf('production'))->toBe(3)
        ->and($overridesOf('worker-batch'))->toBe(2)
        ->and($this->repository->alertRules($this->team, 'nope'))->toBe([]);
});
