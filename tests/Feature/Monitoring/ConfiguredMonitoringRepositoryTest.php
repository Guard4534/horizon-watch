<?php

use App\Data\Monitoring\EnvironmentData;
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

function freshMonitoringRepository(): MonitoringRepository
{
    app()->forgetScopedInstances();

    return app(MonitoringRepository::class);
}

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

    expect($applications)->toHaveCount(1)
        ->and($applications[0]->id)->toBe($alpha->slug);

    expect(app(WallQuery::class)->handle($this->team)->applicationCount)->toBe(1);
});

test('an admin sees an application with zero environments, so they can still reach it', function () {
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
    $repository = freshMonitoringRepository();

    expect($repository->applications($this->team))->toBe([])
        ->and($repository->configurableApplications($this->team))->toBe([])
        ->and($repository->configurableApplication($this->team, $empty->slug))->toBeNull();
});

test('an application whose environments are all hidden still reaches the Applications view of an admin', function () {
    $application = Application::factory()->for($this->team)->create(['name' => 'Fatturaomatic']);
    $environment = Environment::factory()->for($application)->production()->create();

    $this->user->teamMemberships()->where('team_id', $this->team->id)->first()
        ->update(['visibility' => MemberVisibility::Manual->value]);

    $repository = freshMonitoringRepository();

    expect($repository->applications($this->team))->toBe([])
        ->and($repository->environments($this->team))->toBe([])
        ->and($repository->alerts($this->team, AlertState::Open))->toBe([])
        ->and($repository->environment($this->team, $environment->slug))->toBeNull();

    expect($repository->configurableApplications($this->team))->toHaveCount(1)
        ->and($repository->configurableApplication($this->team, $application->slug))->not->toBeNull()
        ->and(array_map(fn ($item) => $item->id, $repository->configurableEnvironments($this->team)))
        ->toBe([$environment->slug])
        ->and($repository->configurableEnvironments($this->team)[0]->watched)->toBeFalse();
});

test('an application whose environments are all hidden stays hidden for a member', function () {
    $application = Application::factory()->for($this->team)->create(['name' => 'Fatturaomatic']);
    $environment = Environment::factory()->for($application)->production()->create();

    $member = User::factory()->create();
    $this->team->members()->attach($member, [
        'role' => TeamRole::Member->value,
        'visibility' => MemberVisibility::Manual->value,
    ]);

    $this->actingAs($member);
    $repository = freshMonitoringRepository();

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

    $repository = freshMonitoringRepository();

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
        'failed_in_window' => 12,
        'failed_window_minutes' => 10080,
        'workers' => 9,
        'jobs_per_minute' => 180,
        'node_count' => 2,
    ], state: ['latency_ms' => 84]);

    $data = $this->repository->environment($this->team, $environment->slug);

    expect($data->status)->toBe(EnvironmentStatus::Degraded)
        ->and($data->pending)->toBe(2400)
        ->and($data->maxWaitSeconds)->toBe(33)
        ->and($data->failedInWindow)->toBe(12)
        ->and($data->failedWindowMinutes)->toBe(10080)
        ->and($data->workers)->toBe(9)
        ->and($data->jobsPerMinute)->toBe(180)
        ->and($data->nodeCount)->toBe(2)
        ->and($data->latencyMs)->toBe(84)
        ->and($data->lastReadingAt)->toBe(now()->subSeconds(20)->toIso8601String())
        ->and($data->stale)->toBeFalse()
        ->and($data->pollingEnabled)->toBeTrue()
        ->and($data->readingError)->toBeNull();
});

test('an environment never read has nothing to say, then does not answer once three intervals pass', function () {
    $application = Application::factory()->for($this->team)->create();
    $environment = Environment::factory()->for($application)->production()->create(['poll_interval_seconds' => 15]);

    $this->travel(45)->seconds();
    $waiting = freshMonitoringRepository()->environment($this->team, $environment->slug);

    expect($waiting->status)->toBeNull()
        ->and($waiting->stale)->toBeFalse();

    $this->travel(1)->seconds();
    $this->repository = freshMonitoringRepository();
    $data = $this->repository->environment($this->team, $environment->slug);

    expect($data->status)->toBe(EnvironmentStatus::Unreachable)
        ->and([$data->pending, $data->maxWaitSeconds, $data->failedInWindow, $data->workers, $data->jobsPerMinute, $data->nodeCount])
        ->toBe([0, 0, 0, 0, 0, 0])
        ->and($data->failedWindowMinutes)->toBe(10080)
        ->and($data->trend)->toBe(array_fill(0, 12, 0))
        ->and($data->trendPercent)->toBeNull()
        ->and($data->latencyMs)->toBeNull()
        ->and($data->lastReadingAt)->toBeNull()
        ->and($data->stale)->toBeTrue()
        ->and($data->readingError)->toBeNull()
        ->and($this->repository->nodes($this->team, $environment->slug))->toBe([])
        ->and($this->repository->queues($this->team, $environment->slug))->toBe([])
        ->and($this->repository->failedJobs($this->team, $environment->slug))->toBe([])
        ->and($this->repository->longRunningJobs($this->team, $environment->slug))->toBe([])
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
        ->and($unread->status)->toBeNull();
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
        ['hostname' => 'queue-1.example.com', 'status' => 'active', 'workers' => 8, 'supervisorCount' => 2, 'queueCount' => 3, 'seenSecondsAgo' => 0],
        ['hostname' => 'queue-2.example.com', 'status' => 'paused', 'workers' => 0, 'supervisorCount' => 1, 'queueCount' => 1, 'seenSecondsAgo' => 0],
    ]);

    expect(array_map(fn ($queue) => [$queue->name, $queue->supervisor, $queue->runtimeSeconds, $queue->status], $queues))->toBe([
        ['default', 'supervisor-1', 0.25, EnvironmentStatus::Active],
        ['emails', null, null, EnvironmentStatus::Degraded],
        ['reports', 'supervisor-2', 2.0, EnvironmentStatus::Degraded],
        ['imports', 'supervisor-2', 1.5, EnvironmentStatus::Degraded],
    ]);
});

test('a node is dated from the last reading that listed it', function () {
    $application = Application::factory()->for($this->team)->create();
    $environment = Environment::factory()->for($application)->production()->create();
    $node = fn (string $hostname, array $extra = []) => ['hostname' => $hostname, 'status' => 'running', 'workers' => 1, 'supervisors' => 1, 'queues' => 1, ...$extra];

    Readings::record($environment, EnvironmentStatus::Unreachable, snapshot: ['captured_at' => now()->subSeconds(10)], state: [
        'nodes' => [
            $node('seen.example.com', ['seenAt' => now()->subSeconds(40)->toIso8601String()]),
            $node('legacy.example.com'),
            $node('ahead.example.com', ['seenAt' => now()->addSeconds(3)->toIso8601String()]),
        ],
    ]);

    $nodes = $this->repository->nodes($this->team, $environment->slug);

    expect(array_column(array_map(fn ($item) => $item->toArray(), $nodes), 'seenSecondsAgo', 'hostname'))->toBe([
        'seen.example.com' => 40,
        'legacy.example.com' => 10,
        'ahead.example.com' => 0,
    ]);
});

test('an environment carries the pending trend of its last hour', function () {
    $application = Application::factory()->for($this->team)->create();
    $environment = Environment::factory()->for($application)->production()->create();
    $other = Environment::factory()->for($application)->staging()->create();

    foreach ([10, 10, 10, 15, 15, 15] as $index => $pending) {
        EnvironmentSnapshot::factory()->for($environment)->create([
            'captured_at' => now()->subMinutes(5 * (5 - $index)),
            'pending' => $pending,
        ]);
    }

    Readings::record($other, snapshot: ['pending' => 900]);

    $data = $this->repository->environment($this->team, $environment->slug);
    $otherData = $this->repository->environment($this->team, $other->slug);

    expect($data->trend)->toBe([0, 0, 0, 0, 0, 0, 10, 10, 10, 15, 15, 15])
        ->and($data->trendPercent)->toBe(50)
        ->and($otherData->trend)->toBe([...array_fill(0, 11, 0), 900])
        ->and($otherData->trendPercent)->toBeNull();
});

test('the trend is read once for every environment on the page, whatever their number', function () {
    $application = Application::factory()->for($this->team)->create();
    $created = 0;
    $add = function (int $count) use ($application, &$created) {
        for ($index = 0; $index < $count; $index++) {
            Readings::record(Environment::factory()->for($application)->create(['name' => 'worker-'.++$created]));
        }
    };

    $measure = function () {
        DB::flushQueryLog();
        freshMonitoringRepository();
        DB::enableQueryLog();
        $page = app(WallQuery::class)->handle($this->team);
        DB::disableQueryLog();

        return [$page, collect(DB::getQueryLog())->pluck('query')];
    };

    $add(2);
    [$few, $fewQueries] = $measure();

    $add(13);
    [$many, $manyQueries] = $measure();

    expect($few->environments)->toHaveCount(2)
        ->and($many->environments)->toHaveCount(15)
        ->and(collect($many->environments)->every(fn ($environment) => count($environment->trend) === 12))->toBeTrue()
        ->and($fewQueries->filter(fn (string $query) => str_contains($query, 'avg(pending)')))->toHaveCount(1)
        ->and($manyQueries->filter(fn (string $query) => str_contains($query, 'avg(pending)')))->toHaveCount(1)
        ->and($manyQueries)->toHaveCount($fewQueries->count());
});

test('the trend query only ever asks for what the viewer watches', function () {
    $application = Application::factory()->for($this->team)->create();
    $production = Environment::factory()->for($application)->production()->create();
    $staging = Environment::factory()->for($application)->staging()->create();
    Readings::record($production);
    Readings::record($staging);

    $this->user->teamMemberships()->where('team_id', $this->team->id)->first()
        ->update(['visibility' => MemberVisibility::NonProduction->value]);
    freshMonitoringRepository();

    DB::enableQueryLog();
    app(WallQuery::class)->handle($this->team);

    $trend = collect(DB::getQueryLog())->filter(fn (array $query) => str_contains($query['query'], 'avg(pending)'))->sole();

    expect($trend['bindings'])->toContain($staging->id)
        ->not->toContain($production->id);
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

    expect(array_map(fn ($job) => $job->toArray(), $running))->toBe([
        ['job' => 'App\\Jobs\\RebuildIndex', 'queue' => 'default', 'elapsedSeconds' => 1200, 'startedAt' => '09:40'],
        ['job' => 'App\\Jobs\\ExportLedger', 'queue' => 'reports', 'elapsedSeconds' => 121, 'startedAt' => '09:57'],
    ]);
});

test('a young outage is down at once and opens its alert only after the rule minutes', function () {
    $application = Application::factory()->for($this->team)->create(['name' => 'Alpha']);
    $production = Environment::factory()->for($application)->production()->create();

    EnvironmentSnapshot::factory()->for($production)->failed()->create(['captured_at' => now()->subSeconds(90)]);
    Readings::record($production, EnvironmentStatus::Unreachable);

    expect($this->repository->environment($this->team, $production->slug)->status)->toBe(EnvironmentStatus::Unreachable)
        ->and($this->repository->alerts($this->team, AlertState::Open))->toBe([]);

    $this->travel(30)->seconds();

    expect(array_map(fn ($alert) => $alert->id, app()->build(ConfiguredMonitoringRepository::class)->alerts($this->team, AlertState::Open)))
        ->toBe(["{$production->slug}:endpoint.unreachable"]);
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
    $repository = freshMonitoringRepository();

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

    expect($subjects(freshMonitoringRepository()))->not->toContain('Alpha · production')
        ->toContain('Alpha · staging');

    $membership->update(['visibility' => MemberVisibility::Manual->value]);

    expect(freshMonitoringRepository()->sentNotifications($this->team))->toBe([]);
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
    $this->repository->configurableEnvironments($this->team);

    $queries = collect(DB::getQueryLog())->pluck('query');

    expect($queries->filter(fn (string $query) => str_contains($query, 'from "environment_states"')))->toHaveCount(1)
        ->and($queries->filter(fn (string $query) => str_contains($query, 'avg(pending)')))->toHaveCount(1)
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

test('an unwatched row of the configuration view carries no reading at all', function () {
    $application = Application::factory()->for($this->team)->create(['name' => 'Alpha']);
    $watched = Environment::factory()->for($application)->staging()->create();
    $hidden = Environment::factory()->for($application)->production()->create(['poll_interval_seconds' => 15]);
    Readings::record($watched, EnvironmentStatus::Degraded, [AlertRuleMetric::QueuePending], snapshot: ['pending' => 3000]);
    Readings::record($hidden, EnvironmentStatus::Unreachable, snapshot: ['captured_at' => now()->subHour()], state: [
        'error' => ReadingError::Unauthorized,
    ]);
    EnvironmentSnapshot::factory()->for($hidden)->create(['captured_at' => now()->subHours(2), 'pending' => 777, 'workers' => 5]);

    $membership = $this->user->teamMemberships()->where('team_id', $this->team->id)->first();
    $membership->update(['visibility' => MemberVisibility::Manual->value]);
    $membership->visibleEnvironments()->attach([$watched->id]);

    $rows = collect(freshMonitoringRepository()->configurableEnvironments($this->team))->keyBy('id');
    $row = $rows[$hidden->slug];

    expect($row->watched)->toBeFalse()
        ->and($row->status)->toBeNull()
        ->and([$row->pending, $row->maxWaitSeconds, $row->failedInWindow, $row->workers, $row->jobsPerMinute, $row->nodeCount])
        ->toBe([0, 0, 0, 0, 0, 0])
        ->and($row->latencyMs)->toBeNull()
        ->and($row->readingError)->toBeNull()
        ->and($row->lastReadingAt)->toBeNull()
        ->and($row->trend)->toBe([])
        ->and($row->trendPercent)->toBeNull()
        ->and($row->stale)->toBeFalse()
        ->and($rows[$watched->slug]->status)->toBe(EnvironmentStatus::Degraded)
        ->and($rows[$watched->slug]->pending)->toBe(3000);
});

test('a url saved with credentials is handed out without them', function (string $saved, string $shown) {
    $application = Application::factory()->for($this->team)->create();
    $environment = Environment::factory()->for($application)->production()->create();
    DB::table('environments')->where('id', $environment->id)->update(['horizon_url' => $saved]);

    expect(freshMonitoringRepository()->environment($this->team, $environment->slug)->horizonUrl)->toBe($shown)
        ->and(freshMonitoringRepository()->configurableEnvironments($this->team)[0]->horizonUrl)->toBe($shown);
})->with([
    'user and password' => ['https://monitor:s3cret@app.example.com/horizon', 'https://app.example.com/horizon'],
    'user only' => ['http://monitor@app.example.com:8080/horizon', 'http://app.example.com:8080/horizon'],
    'an @ inside the password' => ['https://monitor:p@ss@app.example.com/horizon', 'https://app.example.com/horizon'],
    'an @ in the path is not userinfo' => ['https://horizon.example.com/ops@team/horizon', 'https://horizon.example.com/ops@team/horizon'],
    'an @ further on is not userinfo' => ['https://app.example.com/horizon?next=a@b', 'https://app.example.com/horizon?next=a@b'],
    'no credentials' => ['https://app.example.com/horizon', 'https://app.example.com/horizon'],
]);

test('a row with nothing to say sorts between degraded and active', function () {
    $application = Application::factory()->for($this->team)->create(['name' => 'Alpha']);
    $active = Environment::factory()->for($application)->production()->create();
    $paused = Environment::factory()->for($application)->staging()->create();
    $waiting = Environment::factory()->for($application)->develop()->create(['polling_enabled' => false]);
    $down = Environment::factory()->for($application)->demo()->create();
    $degraded = Environment::factory()->for($application)->testing()->create();
    Readings::record($active, snapshot: ['pending' => 5000]);
    Readings::record($paused, EnvironmentStatus::Paused, [AlertRuleMetric::HorizonPaused], snapshot: ['pending' => 10]);
    Readings::record($down, EnvironmentStatus::Unreachable);
    Readings::record($degraded, EnvironmentStatus::Degraded, [AlertRuleMetric::QueueMaxWait], snapshot: ['pending' => 0]);

    $environments = $this->repository->environments($this->team);
    usort($environments, EnvironmentData::compareBySeverityThenPending(...));

    expect(array_map(fn ($environment) => $environment->id, $environments))
        ->toBe([$down->slug, $paused->slug, $degraded->slug, $waiting->slug, $active->slug]);
});

test('a state whose snapshots were all pruned keeps its status and detail, with no numbers', function () {
    $application = Application::factory()->for($this->team)->create();
    $environment = Environment::factory()->for($application)->production()->create(['polling_enabled' => false]);
    Readings::record($environment, EnvironmentStatus::Degraded, [AlertRuleMetric::QueuePending], snapshot: [
        'captured_at' => now()->subDays(40),
        'pending' => 4000,
    ]);
    EnvironmentSnapshot::query()->delete();

    $data = $this->repository->environment($this->team, $environment->slug);

    expect($data->status)->toBe(EnvironmentStatus::Degraded)
        ->and($data->pending)->toBe(0)
        ->and($data->lastReadingAt)->toBe(now()->subDays(40)->toIso8601String())
        ->and($this->repository->queues($this->team, $environment->slug))->toHaveCount(3)
        ->and($this->repository->alerts($this->team, AlertState::Open))->toBe([]);
});

test('an anomaly older than the look-back is reported as truncated, capped at a day', function () {
    $application = Application::factory()->for($this->team)->create();
    $environment = Environment::factory()->for($application)->production()->create();

    foreach ([30, 26, 20, 10] as $hoursAgo) {
        EnvironmentSnapshot::factory()->for($environment)->failed()->create(['captured_at' => now()->subHours($hoursAgo)]);
    }
    Readings::record($environment, EnvironmentStatus::Unreachable);

    $alert = $this->repository->alerts($this->team, AlertState::Open)[0];

    expect($alert->metric)->toBe(AlertRuleMetric::EndpointUnreachable)
        ->and($alert->sinceTruncated)->toBeTrue()
        ->and($alert->minutesAgo)->toBe(1440);
});

test('a run that starts inside the look-back is not truncated, even with older unrelated readings', function () {
    $application = Application::factory()->for($this->team)->create();
    $environment = Environment::factory()->for($application)->production()->create();
    EnvironmentSnapshot::factory()->for($environment)->create(['captured_at' => now()->subHours(30)]);
    EnvironmentSnapshot::factory()->for($environment)->failed()->create(['captured_at' => now()->subHours(3)]);
    Readings::record($environment, EnvironmentStatus::Unreachable);

    $alert = $this->repository->alerts($this->team, AlertState::Open)[0];

    expect($alert->sinceTruncated)->toBeFalse()
        ->and($alert->minutesAgo)->toBe(180);
});

test('a paused horizon opens its own anomaly, with the thresholds it still breaks', function () {
    $application = Application::factory()->for($this->team)->create();
    $environment = Environment::factory()->for($application)->production()->create();
    Readings::record($environment, EnvironmentStatus::Paused, [AlertRuleMetric::HorizonPaused, AlertRuleMetric::QueuePending], snapshot: [
        'pending' => 50_000,
    ]);

    $alerts = $this->repository->alerts($this->team, AlertState::Open);

    expect(array_map(fn ($alert) => $alert->metric, $alerts))->toBe([AlertRuleMetric::HorizonPaused, AlertRuleMetric::QueuePending])
        ->and($alerts[0]->environmentStatus)->toBe(EnvironmentStatus::Paused)
        ->and($alerts[0]->pending)->toBe(50_000)
        ->and($alerts[0]->severity->value)->toBe('warning');
});

test('the page and its sidebar badge share one repository, so the anomalies are read once', function () {
    $application = Application::factory()->for($this->team)->create();
    $environment = Environment::factory()->for($application)->production()->create();
    Readings::record($environment, EnvironmentStatus::Unreachable);
    $this->user->switchTeam($this->team);

    DB::enableQueryLog();

    $this->get(route('alerts.index', ['current_team' => $this->team->slug]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('openAlertCount', 1));

    expect(collect(DB::getQueryLog())->filter(fn (array $query) => str_contains($query['query'], 'with latest as')))->toHaveCount(1);
});

test('the scoped repository does not carry one request into the next', function () {
    $application = Application::factory()->for($this->team)->create();
    $production = Environment::factory()->for($application)->production()->create();
    Readings::record($production, EnvironmentStatus::Unreachable);
    $this->user->switchTeam($this->team);

    $restricted = User::factory()->create();
    $this->team->members()->attach($restricted, [
        'role' => TeamRole::Member->value,
        'visibility' => MemberVisibility::NonProduction->value,
    ]);
    $restricted->switchTeam($this->team);

    $this->get(route('wall', ['current_team' => $this->team->slug]))
        ->assertInertia(fn ($page) => $page->where('openAlertCount', 1)->has('page.environments', 1));

    $this->actingAs($restricted)
        ->get(route('wall', ['current_team' => $this->team->slug]))
        ->assertInertia(fn ($page) => $page->where('openAlertCount', 0)->where('page.environments', []));
});
