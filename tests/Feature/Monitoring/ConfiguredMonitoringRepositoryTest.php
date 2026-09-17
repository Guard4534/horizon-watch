<?php

use App\Data\Monitoring\NotificationSettingsData;
use App\Enums\AlertRuleMetric;
use App\Enums\AlertState;
use App\Enums\EnvironmentStatus;
use App\Enums\MemberVisibility;
use App\Enums\ReadingError;
use App\Enums\RuleOrigin;
use App\Enums\SeriesRange;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentSnapshot;
use App\Models\Team;
use App\Models\User;
use App\Monitoring\ConfiguredMonitoringRepository;
use App\Monitoring\MonitoringRepository;
use App\Queries\WallQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\Readings;

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
    $application = Application::factory()->for($this->team)->create(['name' => 'Fatturaomatic']);
    $production = Environment::factory()->for($application)->production()->create();
    $staging = Environment::factory()->for($application)->staging()->create();
    Readings::record($production, EnvironmentStatus::Unreachable);
    Readings::record($staging);

    $openBefore = $this->repository->alerts($this->team, AlertState::Open);
    expect(collect($openBefore)->pluck('environmentId')->all())->toBe([$production->slug]);

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
    expect($openAfter)->toBe([]);
});

test('an environment reads its latest stored reading', function () {
    $application = Application::factory()->for($this->team)->create();
    $environment = Environment::factory()->for($application)->production()->create(['poll_interval_seconds' => 15]);

    EnvironmentSnapshot::factory()->for($environment)->create(['captured_at' => now()->subMinutes(2), 'pending' => 1]);
    Readings::record($environment, EnvironmentStatus::Degraded, [AlertRuleMetric::QueuePending], snapshot: [
        'captured_at' => now()->subSeconds(20),
        'pending' => 2400,
        'max_wait_seconds' => 33,
        'failed_last_24_hours' => 12,
        'workers' => 9,
        'jobs_per_minute' => 180,
        'node_count' => 2,
    ], state: ['latency_ms' => 84]);

    $data = $this->repository->environment($this->team, $environment->slug);

    expect($data->status)->toBe(EnvironmentStatus::Degraded)
        ->and($data->pending)->toBe(2400)
        ->and($data->maxWaitSeconds)->toBe(33)
        ->and($data->failedLast24Hours)->toBe(12)
        ->and($data->workers)->toBe(9)
        ->and($data->jobsPerMinute)->toBe(180)
        ->and($data->nodeCount)->toBe(2)
        ->and($data->latencyMs)->toBe(84)
        ->and($data->lastReadingAt)->toBe(now()->subSeconds(20)->toIso8601String())
        ->and($data->stale)->toBeFalse()
        ->and($data->pollingEnabled)->toBeTrue()
        ->and($data->readingError)->toBeNull();
});

test('an environment never read does not answer, counts nothing and is stale', function () {
    $application = Application::factory()->for($this->team)->create();
    $environment = Environment::factory()->for($application)->production()->create();

    $data = $this->repository->environment($this->team, $environment->slug);

    expect($data->status)->toBe(EnvironmentStatus::Unreachable)
        ->and([$data->pending, $data->maxWaitSeconds, $data->failedLast24Hours, $data->workers, $data->jobsPerMinute, $data->nodeCount])
        ->toBe([0, 0, 0, 0, 0, 0])
        ->and($data->latencyMs)->toBeNull()
        ->and($data->lastReadingAt)->toBeNull()
        ->and($data->stale)->toBeTrue()
        ->and($data->readingError)->toBeNull()
        ->and($this->repository->nodes($this->team, $environment->slug))->toBe([])
        ->and($this->repository->queues($this->team, $environment->slug))->toBe([])
        ->and($this->repository->failedJobs($this->team, $environment->slug))->toBe([])
        ->and($this->repository->longRunningJobs($this->team, $environment->slug))->toBe([])
        // No snapshot, so no anomaly either: nothing was ever measured.
        ->and($this->repository->alerts($this->team, AlertState::Open))->toBe([]);
});

test('an environment goes stale after three silent intervals and keeps its last reading', function () {
    $application = Application::factory()->for($this->team)->create();
    $environment = Environment::factory()->for($application)->production()->create(['poll_interval_seconds' => 60]);
    Readings::record($environment, snapshot: ['captured_at' => now()->subSeconds(181), 'workers' => 4]);

    $data = $this->repository->environment($this->team, $environment->slug);

    expect($data->stale)->toBeTrue()
        ->and($data->status)->toBe(EnvironmentStatus::Active)
        ->and($data->workers)->toBe(4);
});

test('a paused collection shows the last reading and is never stale', function () {
    $application = Application::factory()->for($this->team)->create();
    $read = Environment::factory()->for($application)->production()->create(['polling_enabled' => false]);
    $unread = Environment::factory()->for($application)->staging()->create(['polling_enabled' => false]);
    Readings::record($read, EnvironmentStatus::Paused, snapshot: ['captured_at' => now()->subDays(3), 'pending' => 70]);

    $read = $this->repository->environment($this->team, $read->slug);
    $unread = $this->repository->environment($this->team, $unread->slug);

    expect($read->pollingEnabled)->toBeFalse()
        ->and($read->stale)->toBeFalse()
        ->and($read->status)->toBe(EnvironmentStatus::Paused)
        ->and($read->pending)->toBe(70)
        ->and($unread->pollingEnabled)->toBeFalse()
        ->and($unread->stale)->toBeFalse()
        ->and($unread->status)->toBe(EnvironmentStatus::Unreachable);
});

test('a failed reading reports its reason and keeps the previous detail under the environment status', function () {
    $application = Application::factory()->for($this->team)->create();
    $environment = Environment::factory()->for($application)->production()->create();
    Readings::record($environment, EnvironmentStatus::Unreachable, state: ['error' => ReadingError::Unauthorized]);

    $data = $this->repository->environment($this->team, $environment->slug);
    $nodes = $this->repository->nodes($this->team, $environment->slug);
    $queues = $this->repository->queues($this->team, $environment->slug);

    expect($data->readingError)->toBe(ReadingError::Unauthorized)
        ->and($data->latencyMs)->toBeNull()
        ->and($data->pending)->toBe(0)
        ->and($nodes)->toHaveCount(1)
        ->and($nodes[0]->status)->toBe(EnvironmentStatus::Unreachable)
        ->and($queues)->toHaveCount(3)
        ->and(collect($queues)->every(fn ($queue) => $queue->status === EnvironmentStatus::Unreachable))->toBeTrue();
});

test('nodes and queues come from the stored detail', function () {
    $application = Application::factory()->for($this->team)->create();
    $environment = Environment::factory()->for($application)->production()->create();
    Readings::record($environment, state: [
        'nodes' => [
            ['hostname' => 'queue-1.example.com', 'status' => 'running', 'workers' => 8, 'supervisors' => 2, 'queues' => 3],
            ['hostname' => 'queue-2.example.com', 'status' => 'paused', 'workers' => 0, 'supervisors' => 1, 'queues' => 1],
        ],
        'queues' => [
            ['name' => 'default', 'supervisor' => 'supervisor-1', 'workers' => 4, 'pending' => 10, 'waitSeconds' => 3, 'runtimeSeconds' => 0.25],
            ['name' => 'emails', 'supervisor' => null, 'workers' => 0, 'pending' => 5, 'waitSeconds' => 0, 'runtimeSeconds' => null],
            ['name' => 'reports', 'supervisor' => 'supervisor-2', 'workers' => 2, 'pending' => 0, 'waitSeconds' => 61, 'runtimeSeconds' => 2],
            ['name' => 'imports', 'supervisor' => 'supervisor-2', 'workers' => 2, 'pending' => 2001, 'waitSeconds' => 0, 'runtimeSeconds' => 1.5],
        ],
    ]);

    $nodes = $this->repository->nodes($this->team, $environment->slug);
    $queues = $this->repository->queues($this->team, $environment->slug);

    expect(array_map(fn ($node) => $node->toArray(), $nodes))->toBe([
        ['hostname' => 'queue-1.example.com', 'status' => 'active', 'workers' => 8, 'supervisorCount' => 2, 'queueCount' => 3],
        ['hostname' => 'queue-2.example.com', 'status' => 'paused', 'workers' => 0, 'supervisorCount' => 1, 'queueCount' => 1],
    ]);

    expect(array_map(fn ($queue) => [$queue->name, $queue->supervisor, $queue->runtimeSeconds, $queue->status], $queues))->toBe([
        ['default', 'supervisor-1', 0.25, EnvironmentStatus::Active],
        // Work waiting and nobody to do it.
        ['emails', null, null, EnvironmentStatus::Degraded],
        // Above the default max wait.
        ['reports', 'supervisor-2', 2.0, EnvironmentStatus::Degraded],
        // Above the default pending threshold.
        ['imports', 'supervisor-2', 1.5, EnvironmentStatus::Degraded],
    ]);
});

test('failed jobs and long-running jobs are dated from the stored timestamps', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-17 10:00:00', 'UTC'));

    $application = Application::factory()->for($this->team)->create();
    $environment = Environment::factory()->for($application)->production()->create();
    Readings::record($environment, state: [
        'failed_jobs' => [
            ['job' => 'App\\Jobs\\SendInvoiceEmail', 'queue' => 'emails', 'exception' => 'RuntimeException: timeout', 'tries' => 3, 'failedAt' => '2026-09-17T09:47:30+00:00'],
        ],
        'pending_jobs' => [
            ['job' => 'App\\Jobs\\ShortOne', 'queue' => 'default', 'reservedAt' => '2026-09-17T09:58:00+00:00'],
            ['job' => 'App\\Jobs\\ExportLedger', 'queue' => 'reports', 'reservedAt' => '2026-09-17T09:57:59+00:00'],
            ['job' => 'App\\Jobs\\RebuildIndex', 'queue' => 'default', 'reservedAt' => '2026-09-17T09:40:00+00:00'],
        ],
    ]);

    $failed = $this->repository->failedJobs($this->team, $environment->slug);
    $running = $this->repository->longRunningJobs($this->team, $environment->slug);

    expect($failed)->toHaveCount(1)
        ->and($failed[0]->toArray())->toBe([
            'job' => 'App\\Jobs\\SendInvoiceEmail',
            'queue' => 'emails',
            'exception' => 'RuntimeException: timeout',
            'tries' => 3,
            'minutesAgo' => 12,
        ]);

    // Exactly at the 120-second threshold is not over it; the longest runs first.
    expect(array_map(fn ($job) => $job->toArray(), $running))->toBe([
        ['job' => 'App\\Jobs\\RebuildIndex', 'queue' => 'default', 'elapsedSeconds' => 1200, 'startedAt' => '09:40'],
        ['job' => 'App\\Jobs\\ExportLedger', 'queue' => 'reports', 'elapsedSeconds' => 121, 'startedAt' => '09:57'],
    ]);
});

test('open alerts come from the stored anomalies, worst environment first, and nothing else is listed yet', function () {
    $application = Application::factory()->for($this->team)->create(['name' => 'Alpha']);
    $production = Environment::factory()->for($application)->production()->create();
    $staging = Environment::factory()->for($application)->staging()->create();
    $develop = Environment::factory()->for($application)->develop()->create();

    EnvironmentSnapshot::factory()->for($staging)->degraded([AlertRuleMetric::QueuePending])->create(['captured_at' => now()->subMinutes(30)]);
    Readings::record($staging, EnvironmentStatus::Degraded, [AlertRuleMetric::QueueMaxWait, AlertRuleMetric::QueuePending], snapshot: ['pending' => 2500]);
    EnvironmentSnapshot::factory()->for($production)->failed()->create(['captured_at' => now()->subMinutes(4)]);
    Readings::record($production, EnvironmentStatus::Unreachable);
    Readings::record($develop);

    $open = $this->repository->alerts($this->team, AlertState::Open);

    expect(array_map(fn ($alert) => [$alert->id, $alert->minutesAgo], $open))->toBe([
        ["{$production->slug}:endpoint.unreachable", 4],
        ["{$staging->slug}:queue.pending", 30],
        ["{$staging->slug}:queue.max_wait", 0],
    ])
        ->and($open[1]->environmentStatus)->toBe(EnvironmentStatus::Degraded)
        ->and($open[1]->pending)->toBe(2500)
        ->and($open[1]->threshold)->toBe(AlertRuleMetric::QueuePending->defaultThreshold())
        ->and($this->repository->alerts($this->team, AlertState::Muted))->toBe([])
        ->and($this->repository->alerts($this->team, AlertState::Resolved))->toBe([]);
});

test('the series only sum what the viewer watches', function () {
    $application = Application::factory()->for($this->team)->create();
    $production = Environment::factory()->for($application)->production()->create();
    $staging = Environment::factory()->for($application)->staging()->create();
    Readings::record($production, snapshot: ['jobs_per_minute' => 100, 'max_wait_seconds' => 50]);
    Readings::record($staging, snapshot: ['jobs_per_minute' => 7, 'max_wait_seconds' => 5]);

    expect(last($this->repository->throughputSeries($this->team, null, SeriesRange::ThreeHours)))->toBe(107);

    $this->user->teamMemberships()->where('team_id', $this->team->id)->first()
        ->update(['visibility' => MemberVisibility::NonProduction->value]);
    $repository = app(MonitoringRepository::class);

    expect(last($repository->throughputSeries($this->team, null, SeriesRange::ThreeHours)))->toBe(7)
        ->and($repository->throughputSeries($this->team, $production->slug, SeriesRange::ThreeHours))->toBe([])
        ->and($repository->maxWaitSeries($this->team, $production->slug, SeriesRange::ThreeHours))->toBe([])
        ->and(last($repository->maxWaitSeries($this->team, $staging->slug, SeriesRange::ThreeHours)))->toBe(5);
});

test('sent notifications only name watched environments', function () {
    $application = Application::factory()->for($this->team)->create(['name' => 'Alpha']);
    $production = Environment::factory()->for($application)->production()->create();
    $staging = Environment::factory()->for($application)->staging()->create();
    Readings::record($production, EnvironmentStatus::Inactive, [AlertRuleMetric::HorizonMasterInactive]);
    Readings::record($staging);

    $subjects = fn ($repository) => array_map(fn ($notification) => $notification->subject, $repository->sentNotifications($this->team));

    expect($subjects($this->repository))->toContain('Alpha · production', 'Alpha · staging');

    $membership = $this->user->teamMemberships()->where('team_id', $this->team->id)->first();
    $membership->update(['visibility' => MemberVisibility::NonProduction->value]);

    expect($subjects(app(MonitoringRepository::class)))->not->toContain('Alpha · production')
        ->toContain('Alpha · staging');

    $membership->update(['visibility' => MemberVisibility::Manual->value]);

    expect(app(MonitoringRepository::class)->sentNotifications($this->team))->toBe([]);
});

test('the stored states are read once per view, however many panels ask', function () {
    $application = Application::factory()->for($this->team)->create();
    $environments = Environment::factory()->for($application)->count(3)->sequence(
        ['name' => 'production'], ['name' => 'staging'], ['name' => 'develop'],
    )->create();
    $environments->each(fn (Environment $environment) => Readings::record($environment));

    $slug = $environments->first()->slug;

    DB::enableQueryLog();

    $this->repository->environment($this->team, $slug);
    $this->repository->environments($this->team);
    $this->repository->nodes($this->team, $slug);
    $this->repository->queues($this->team, $slug);
    $this->repository->failedJobs($this->team, $slug);
    $this->repository->longRunningJobs($this->team, $slug);
    $this->repository->alerts($this->team, AlertState::Open);
    $this->repository->alerts($this->team, AlertState::Open);
    // The admin's configuration view holds the same environments: nothing
    // left to fetch.
    $this->repository->configurableEnvironments($this->team);

    $queries = collect(DB::getQueryLog())->pluck('query');

    expect($queries->filter(fn (string $query) => str_contains($query, 'from "environment_states"')))->toHaveCount(1)
        ->and($queries->filter(fn (string $query) => str_contains($query, 'with latest as')))->toHaveCount(1);
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
        ->and($this->repository->alertRules($this->team, 'organization'))->toHaveCount(7)
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

    expect($this->repository->alertRules($this->team, 'organization'))->toHaveCount(7)
        ->and($overridesOf('organization'))->toBe(0)
        ->and($overridesOf('production'))->toBe(3)
        ->and($overridesOf('worker-batch'))->toBe(2)
        ->and($this->repository->alertRules($this->team, 'nope'))->toBe([]);
});
