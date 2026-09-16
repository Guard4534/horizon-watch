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

    expect($this->repository->environment($this->team, $bravoStaging->slug)?->id)->toBe($bravoStaging->slug)
        ->and($this->repository->application($this->team, $bravo->slug)?->id)->toBe($bravo->slug);
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

    expect($applications)->toHaveCount(1)
        ->and($applications[0]->id)->toBe($alpha->slug)
        ->and($this->repository->application($this->team, $alpha->slug))->not->toBeNull()
        ->and($this->repository->application($this->team, $bravo->slug))->toBeNull()
        ->and($this->repository->application($this->team, $charlie->slug))->toBeNull();

    // The wall's applicationCount goes through the same filtered list.
    expect(app(WallQuery::class)->handle($this->team)->applicationCount)->toBe(1);
});

test('an admin sees an application with zero environments, so they can still reach it', function () {
    // $this->user is Admin (see beforeEach): allowed to manage applications.
    $empty = Application::factory()->for($this->team)->create(['name' => 'Empty']);

    $applications = $this->repository->applications($this->team);

    expect($applications)->toHaveCount(1)
        ->and($applications[0]->id)->toBe($empty->slug)
        ->and($this->repository->application($this->team, $empty->slug))->not->toBeNull();
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
        ->and($repository->application($this->team, $empty->slug))->toBeNull();
});

test('an application whose environments are all hidden stays hidden even for an admin', function () {
    // $this->user is Admin — has ManageApplications — but their own
    // visibility is manual and grants nothing: the zero-environment
    // exception must not leak into "has environments, all hidden".
    $application = Application::factory()->for($this->team)->create(['name' => 'Fatturaomatic']);
    Environment::factory()->for($application)->production()->create();

    $this->user->teamMemberships()->where('team_id', $this->team->id)->first()
        ->update(['visibility' => MemberVisibility::Manual->value]);

    $repository = app(MonitoringRepository::class);

    expect($repository->applications($this->team))->toBe([])
        ->and($repository->application($this->team, $application->slug))->toBeNull();
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
        ->and($this->repository->application($this->team, 'anything'))->toBeNull()
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
