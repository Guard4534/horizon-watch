<?php

use App\Actions\Monitoring\PollEnvironment;
use App\Enums\AlertRuleMetric;
use App\Enums\EnvironmentStatus;
use App\Enums\HorizonStatus;
use App\Enums\ReadingCause;
use App\Enums\ReadingError;
use App\Externals\Horizon\Data\HorizonFailedJob;
use App\Externals\Horizon\Data\HorizonMaster;
use App\Externals\Horizon\Data\HorizonPendingJob;
use App\Externals\Horizon\Data\HorizonQueueLoad;
use App\Externals\Horizon\Data\HorizonStats;
use App\Externals\Horizon\Data\HorizonSupervisor;
use App\Externals\Horizon\Exceptions\HorizonReadFailed;
use App\Externals\Horizon\HorizonProbe;
use App\Externals\Horizon\HorizonReader;
use App\Externals\Horizon\HorizonReading;
use App\Externals\Horizon\HorizonTarget;
use App\Jobs\PollEnvironmentJob;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\Environment;
use App\Models\EnvironmentSnapshot;
use App\Models\EnvironmentState;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AnotherAppKey;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-17 10:00:00'));

    $this->reader = new class implements HorizonReader
    {
        /** @var list<HorizonReading|Throwable|Closure(): HorizonReading> */
        public array $results = [];

        /** @var list<string|null> */
        public array $passwords = [];

        /** @var list<array{oldestPendingJobs: bool, knownRuntimes: array<string, float|null>|null}> */
        public array $calls = [];

        public function read(HorizonTarget $target, bool $oldestPendingJobs = false, ?array $knownRuntimes = null): HorizonReading
        {
            $this->passwords[] = $target->password();
            $this->calls[] = ['oldestPendingJobs' => $oldestPendingJobs, 'knownRuntimes' => $knownRuntimes];

            $result = array_shift($this->results) ?? throw new LogicException('No reading queued.');

            if ($result instanceof Closure) {
                $result = $result();
            }

            if ($result instanceof Throwable) {
                throw $result;
            }

            return $result;
        }

        public function probe(HorizonTarget $target): HorizonProbe
        {
            throw new LogicException('The poller never probes.');
        }
    };

    $this->app->instance(HorizonReader::class, $this->reader);

    $this->environment = Environment::factory()->create([
        'basic_auth_user' => 'monitor',
        'basic_auth_password' => 'super-secret-value',
        'poll_interval_seconds' => 15,
    ]);

    $this->reading = fn (array $overrides = []): HorizonReading => new HorizonReading(...array_merge([
        'stats' => new HorizonStats(
            status: 'running',
            jobsPerMinute: 120,
            failedJobs: 5,
            processes: 12,
            pausedMasters: 1,
            wait: ['database:default' => 2, 'database:reports' => 8],
            failedJobsPeriodMinutes: 10080,
        ),
        'masters' => [
            new HorizonMaster(name: 'worker-1.example.com', status: 'running', supervisors: [
                new HorizonSupervisor(name: 'worker-1:supervisor-1', status: 'running', processes: ['database:default' => 3, 'database:emails' => 2]),
                new HorizonSupervisor(name: 'worker-1:supervisor-2', status: 'running', processes: ['database:reports' => 1, 'database:archive' => 0]),
            ]),
            new HorizonMaster(name: 'worker-2.example.com', status: 'paused', supervisors: [
                new HorizonSupervisor(name: 'worker-2:supervisor-1', status: 'paused', processes: ['database:default' => 4, 'database:reports' => 2]),
            ]),
        ],
        'workload' => [
            new HorizonQueueLoad(name: 'default', length: 12, wait: 2, processes: 7),
            new HorizonQueueLoad(name: 'emails', length: 0, wait: 0, processes: 2),
            new HorizonQueueLoad(name: 'reports', length: 3, wait: 8, processes: 3),
            new HorizonQueueLoad(name: 'archive', length: 0, wait: 0, processes: 0),
        ],
        'failedJobs' => [
            new HorizonFailedJob(
                name: 'App\\Jobs\\SendInvoiceEmail',
                queue: 'emails',
                exception: 'RuntimeException: The mail server did not answer',
                attempts: 3,
                failedAt: CarbonImmutable::parse('2026-09-17 09:48:00'),
            ),
        ],
        'pendingJobs' => [
            new HorizonPendingJob(name: 'App\\Jobs\\BuildReport', queue: 'reports', status: 'reserved', reservedAt: CarbonImmutable::parse('2026-09-17 09:59:30')),
            new HorizonPendingJob(name: 'App\\Jobs\\SendInvoiceEmail', queue: 'emails', status: 'pending', reservedAt: null),
            new HorizonPendingJob(name: 'App\\Jobs\\SyncCatalog', queue: 'default', status: 'reserved', reservedAt: null),
        ],
        'queueRuntimes' => ['default' => 0.4, 'reports' => 2.5],
        'latencyMs' => 42,
    ], $overrides));

    $this->poll = fn (): ?EnvironmentSnapshot => app(PollEnvironment::class)->handle($this->environment);
});

test('a reading writes a snapshot and the state in the documented shapes', function () {
    $this->reader->results = [($this->reading)()];

    $snapshot = ($this->poll)();

    expect($snapshot->exists)->toBeTrue()
        ->and($snapshot->environment_id)->toBe($this->environment->id)
        ->and($snapshot->captured_at->toDateTimeString())->toBe('2026-09-17 10:00:00')
        ->and($snapshot->status)->toBe(EnvironmentStatus::Active)
        ->and($snapshot->error)->toBeNull()
        ->and($snapshot->breaches->all())->toBe([])
        ->and($snapshot->pending)->toBe(15)
        ->and($snapshot->max_wait_seconds)->toBe(8)
        ->and($snapshot->jobs_per_minute)->toBe(120)
        ->and($snapshot->failed_in_window)->toBe(5)
        ->and($snapshot->failed_window_minutes)->toBe(10080)
        ->and($snapshot->failed_last_hour)->toBe(1)
        ->and($snapshot->workers)->toBe(12)
        ->and($snapshot->node_count)->toBe(2)
        ->and($snapshot->latency_ms)->toBe(42);

    $state = EnvironmentState::query()->sole();

    expect($state->environment_id)->toBe($this->environment->id)
        ->and($state->captured_at->toDateTimeString())->toBe('2026-09-17 10:00:00')
        ->and($state->status)->toBe(EnvironmentStatus::Active)
        ->and($state->error)->toBeNull()
        ->and($state->horizon_status)->toBe(HorizonStatus::Running)
        ->and($state->latency_ms)->toBe(42)
        ->and($state->nodes)->toBe([
            ['hostname' => 'worker-1.example.com', 'status' => 'running', 'workers' => 6, 'supervisors' => 2, 'queues' => 4, 'seenAt' => '2026-09-17T10:00:00+00:00'],
            ['hostname' => 'worker-2.example.com', 'status' => 'paused', 'workers' => 6, 'supervisors' => 1, 'queues' => 2, 'seenAt' => '2026-09-17T10:00:00+00:00'],
        ])
        ->and($state->queues)->toBe([
            ['name' => 'default', 'supervisor' => 'worker-1:supervisor-1', 'workers' => 7, 'pending' => 12, 'waitSeconds' => 2, 'runtimeSeconds' => 0.4],
            ['name' => 'emails', 'supervisor' => 'worker-1:supervisor-1', 'workers' => 2, 'pending' => 0, 'waitSeconds' => 0, 'runtimeSeconds' => null],
            ['name' => 'reports', 'supervisor' => 'worker-1:supervisor-2', 'workers' => 3, 'pending' => 3, 'waitSeconds' => 8, 'runtimeSeconds' => 2.5],
            ['name' => 'archive', 'supervisor' => null, 'workers' => 0, 'pending' => 0, 'waitSeconds' => 0, 'runtimeSeconds' => null],
        ])
        ->and($state->failed_jobs)->toBe([
            [
                'job' => 'App\\Jobs\\SendInvoiceEmail',
                'queue' => 'emails',
                'exception' => 'RuntimeException: The mail server did not answer',
                'tries' => 3,
                'failedAt' => '2026-09-17T09:48:00+00:00',
            ],
        ])
        ->and($state->pending_jobs)->toBe([
            ['job' => 'App\\Jobs\\BuildReport', 'queue' => 'reports', 'reservedAt' => '2026-09-17T09:59:30+00:00'],
        ]);
});

test('a second reading replaces the state and adds a snapshot', function () {
    $this->reader->results = [
        ($this->reading)(),
        ($this->reading)(['workload' => [new HorizonQueueLoad(name: 'default', length: 1, wait: 0, processes: 7)], 'latencyMs' => 55]),
    ];

    ($this->poll)();
    $this->travel(15)->seconds();
    ($this->poll)();

    expect(EnvironmentSnapshot::query()->where('environment_id', $this->environment->id)->count())->toBe(2)
        ->and(EnvironmentState::query()->count())->toBe(1);

    $state = EnvironmentState::query()->sole();

    expect($state->captured_at->toDateTimeString())->toBe('2026-09-17 10:00:15')
        ->and($state->latency_ms)->toBe(55)
        ->and($state->queues)->toHaveCount(1)
        ->and($state->queues[0]['pending'])->toBe(1);
});

test('a failed reading is stored as unreachable with its reason, keeps the context and empties the reserved jobs', function () {
    $this->reader->results = [
        ($this->reading)(),
        new HorizonReadFailed(ReadingError::Unauthorized),
    ];

    $this->reader->results[0] = ($this->reading)(['stats' => new HorizonStats(
        status: 'paused',
        jobsPerMinute: 0,
        failedJobs: 0,
        processes: 0,
        pausedMasters: 2,
        wait: [],
        failedJobsPeriodMinutes: 10080,
    )]);

    ($this->poll)();
    $before = EnvironmentState::query()->sole();

    $this->travel(15)->seconds();
    $snapshot = ($this->poll)();

    expect($snapshot->status)->toBe(EnvironmentStatus::Unreachable)
        ->and($snapshot->error)->toBe(ReadingError::Unauthorized)
        ->and($snapshot->breaches->all())->toBe([AlertRuleMetric::EndpointUnreachable])
        ->and($snapshot->pending)->toBe(0)
        ->and($snapshot->workers)->toBe(0)
        ->and($snapshot->node_count)->toBe(0)
        ->and($snapshot->latency_ms)->toBeNull();

    $after = EnvironmentState::query()->sole();

    expect($after->status)->toBe(EnvironmentStatus::Unreachable)
        ->and($after->error)->toBe(ReadingError::Unauthorized)
        ->and($after->captured_at->toDateTimeString())->toBe('2026-09-17 10:00:15')
        ->and($after->latency_ms)->toBeNull()
        ->and($before->horizon_status)->toBe(HorizonStatus::Paused)
        ->and($after->horizon_status)->toBe(HorizonStatus::Paused)
        ->and($after->nodes)->toBe($before->nodes)
        ->and($after->queues)->toBe($before->queues)
        ->and($after->failed_jobs)->toBe($before->failed_jobs)
        ->and($before->pending_jobs)->not->toBe([])
        ->and($after->pending_jobs)->toBe([]);
});

test('each node keeps the time of the last reading that listed it, across failed readings', function () {
    $onlyFirst = fn (array $overrides = []) => ($this->reading)([
        'masters' => [($this->reading)()->masters[0]],
        ...$overrides,
    ]);

    $this->reader->results = [
        ($this->reading)(),
        new HorizonReadFailed(ReadingError::Unreachable),
        $onlyFirst(['failedJobs' => null, 'pendingJobs' => null]),
    ];

    ($this->poll)();

    $this->travel(15)->seconds();
    ($this->poll)();
    $afterFailure = EnvironmentState::query()->sole();

    expect($afterFailure->captured_at->toDateTimeString())->toBe('2026-09-17 10:00:15')
        ->and(array_column($afterFailure->nodes, 'seenAt'))->toBe(['2026-09-17T10:00:00+00:00', '2026-09-17T10:00:00+00:00']);

    $this->travel(15)->seconds();
    ($this->poll)();
    $afterRecovery = EnvironmentState::query()->sole();

    expect(array_column($afterRecovery->nodes, 'seenAt', 'hostname'))->toBe([
        'worker-1.example.com' => '2026-09-17T10:00:30+00:00',
    ]);
});

test('the failed-jobs window of a failed reading is the one of the reading before it', function () {
    $good = ($this->reading)();
    $good = ($this->reading)(['stats' => new HorizonStats(
        status: $good->stats->status,
        jobsPerMinute: $good->stats->jobsPerMinute,
        failedJobs: $good->stats->failedJobs,
        processes: $good->stats->processes,
        pausedMasters: $good->stats->pausedMasters,
        wait: $good->stats->wait,
        failedJobsPeriodMinutes: 1440,
    )]);

    $this->reader->results = [
        new HorizonReadFailed(ReadingError::Unreachable),
        $good,
        new HorizonReadFailed(ReadingError::Unreachable),
        new HorizonReadFailed(ReadingError::Unauthorized),
    ];

    expect(($this->poll)()->failed_window_minutes)->toBe(10080);

    $this->travel(15)->seconds();
    expect(($this->poll)()->failed_window_minutes)->toBe(1440);

    $this->travel(15)->seconds();
    expect(($this->poll)()->failed_window_minutes)->toBe(1440);

    $this->travel(15)->seconds();
    expect(($this->poll)()->failed_window_minutes)->toBe(1440);

    $other = Environment::factory()->create();
    expect($other->id)->toBeGreaterThan($this->environment->id);
    $this->reader->results = [new HorizonReadFailed(ReadingError::Unreachable)];

    expect(app(PollEnvironment::class)->handle($other)->failed_window_minutes)->toBe(10080);
});

test('a first reading that fails creates an empty state', function () {
    $this->reader->results = [new HorizonReadFailed(ReadingError::NotHorizon)];

    ($this->poll)();

    $state = EnvironmentState::query()->sole();

    expect($state->status)->toBe(EnvironmentStatus::Unreachable)
        ->and($state->error)->toBe(ReadingError::NotHorizon)
        ->and($state->horizon_status)->toBeNull()
        ->and($state->nodes)->toBe([])
        ->and($state->queues)->toBe([])
        ->and($state->failed_jobs)->toBe([])
        ->and($state->pending_jobs)->toBe([]);
});

test('failed secondary calls keep the failed jobs, empty the reserved jobs and replace the rest', function () {
    $this->reader->results = [
        ($this->reading)(),
        ($this->reading)(['failedJobs' => null, 'pendingJobs' => null, 'workload' => []]),
    ];

    ($this->poll)();
    $before = EnvironmentState::query()->sole();

    ($this->poll)();
    $after = EnvironmentState::query()->sole();

    expect($after->failed_jobs)->toBe($before->failed_jobs)->not->toBe([])
        ->and($before->pending_jobs)->not->toBe([])
        ->and($after->pending_jobs)->toBe([])
        ->and($after->queues)->toBe([]);
});

test('the failed jobs of the last hour are stored and decide the failed-rate breach', function () {
    $burst = array_map(fn (int $minutes) => new HorizonFailedJob(
        name: 'App\\Jobs\\SendInvoiceEmail',
        queue: 'emails',
        exception: 'RuntimeException: The mail server did not answer',
        attempts: 1,
        failedAt: CarbonImmutable::now()->subMinutes($minutes),
    ), [...range(1, 21), 61, 90]);

    $this->reader->results = [($this->reading)(['failedJobs' => $burst])];

    $snapshot = ($this->poll)();

    expect($snapshot->failed_last_hour)->toBe(21)
        ->and($snapshot->status)->toBe(EnvironmentStatus::Degraded)
        ->and($snapshot->breaches->all())->toBe([AlertRuleMetric::JobsFailedPerHour]);
});

test('a failed failed-jobs call counts the kept jobs against the time of the new reading', function () {
    $burst = array_map(fn (int $minutes) => new HorizonFailedJob(
        name: 'App\\Jobs\\SendInvoiceEmail',
        queue: 'emails',
        exception: 'RuntimeException: The mail server did not answer',
        attempts: 1,
        failedAt: CarbonImmutable::now()->subMinutes($minutes),
    ), range(1, 21));

    $this->reader->results = [
        ($this->reading)(['failedJobs' => $burst, 'pendingJobs' => []]),
        ($this->reading)(['failedJobs' => null, 'pendingJobs' => []]),
        ($this->reading)(['failedJobs' => null, 'pendingJobs' => []]),
    ];

    ($this->poll)();

    $this->travel(30)->minutes();
    $kept = ($this->poll)();

    $this->travel(29)->minutes();
    $aged = ($this->poll)();

    expect($kept->failed_last_hour)->toBe(21)
        ->and($kept->breaches->all())->toBe([AlertRuleMetric::JobsFailedPerHour])
        ->and($aged->failed_last_hour)->toBe(1)
        ->and($aged->breaches->all())->toBe([])
        ->and(EnvironmentState::query()->sole()->failed_jobs)->toHaveCount(21);
});

test('a failed failed-jobs call on the first reading counts none', function () {
    $this->reader->results = [($this->reading)(['failedJobs' => null])];

    expect(($this->poll)()->failed_last_hour)->toBe(0);
});

test('a failed reading stores no failed jobs for the last hour', function () {
    $this->reader->results = [($this->reading)(), new HorizonReadFailed(ReadingError::Unreachable)];

    ($this->poll)();

    expect(($this->poll)()->failed_last_hour)->toBe(0);
});

test('a failed secondary call on the first reading leaves those sections empty', function () {
    $this->reader->results = [($this->reading)(['failedJobs' => null, 'pendingJobs' => null])];

    ($this->poll)();

    $state = EnvironmentState::query()->sole();

    expect($state->failed_jobs)->toBe([])
        ->and($state->pending_jobs)->toBe([])
        ->and($state->nodes)->toHaveCount(2);
});

test('an empty secondary list replaces the previous section', function () {
    $this->reader->results = [
        ($this->reading)(),
        ($this->reading)(['failedJobs' => [], 'pendingJobs' => []]),
    ];

    ($this->poll)();
    ($this->poll)();

    $state = EnvironmentState::query()->sole();

    expect($state->failed_jobs)->toBe([])
        ->and($state->pending_jobs)->toBe([]);
});

test('a reading that finished late does not replace a newer state nor move last_polled_at back', function () {
    $this->reader->results = [
        ($this->reading)(['latencyMs' => 99]),
        ($this->reading)(['latencyMs' => 11, 'workload' => []]),
    ];

    $this->travelTo(CarbonImmutable::parse('2026-09-17 10:00:15'));
    ($this->poll)();

    $this->travelTo(CarbonImmutable::parse('2026-09-17 10:00:00'));
    $late = ($this->poll)();

    $state = EnvironmentState::query()->sole();

    expect($state->captured_at->toDateTimeString())->toBe('2026-09-17 10:00:15')
        ->and($state->latency_ms)->toBe(99)
        ->and($state->queues)->toHaveCount(4)
        ->and($this->environment->fresh()->last_polled_at->toDateTimeString())->toBe('2026-09-17 10:00:15')
        ->and($this->environment->last_polled_at->toDateTimeString())->toBe('2026-09-17 10:00:15')
        ->and($late->captured_at->toDateTimeString())->toBe('2026-09-17 10:00:00')
        ->and(EnvironmentSnapshot::query()->count())->toBe(2);
});

test('a reading of the same second replaces the state', function () {
    $this->reader->results = [
        ($this->reading)(['latencyMs' => 99]),
        ($this->reading)(['latencyMs' => 11]),
    ];

    ($this->poll)();
    ($this->poll)();

    expect(EnvironmentState::query()->sole()->latency_ms)->toBe(11);
});

test('a reading of an address the environment left while it was read is dropped', function () {
    $this->reader->results = [function () {
        Environment::query()->whereKey($this->environment->id)->update(['horizon_url' => 'https://preprod.example.com/horizon']);

        return ($this->reading)();
    }];

    expect(($this->poll)())->toBeNull()
        ->and(EnvironmentSnapshot::query()->count())->toBe(0)
        ->and(EnvironmentState::query()->count())->toBe(0)
        ->and($this->environment->fresh()->last_polled_at)->toBeNull();
});

test('a reading survives an edit that keeps the address', function () {
    $this->reader->results = [function () {
        Environment::query()->whereKey($this->environment->id)->update(['name' => 'renamed', 'poll_interval_seconds' => 60]);

        return ($this->reading)();
    }];

    expect(($this->poll)())->not->toBeNull()
        ->and(EnvironmentState::query()->count())->toBe(1);
});

test('an environment deleted while it is being read ends quietly', function () {
    Exceptions::fake();

    $this->reader->results = [function () {
        Environment::query()->whereKey($this->environment->id)->delete();

        return ($this->reading)();
    }];

    expect(($this->poll)())->toBeNull()
        ->and(EnvironmentSnapshot::query()->count())->toBe(0)
        ->and(EnvironmentState::query()->count())->toBe(0);

    Exceptions::assertNothingReported();
});

test('a password that no longer decrypts is stored as a failed reading of its own, without asking Horizon', function () {
    Exceptions::fake();

    DB::table('environments')->where('id', $this->environment->id)->update([
        'basic_auth_password' => AnotherAppKey::encrypt('super-secret-value'),
    ]);

    $snapshot = app(PollEnvironment::class)->handle($this->environment->fresh());
    $state = EnvironmentState::query()->sole();

    expect($this->reader->passwords)->toBe([])
        ->and($snapshot->status)->toBe(EnvironmentStatus::Unreachable)
        ->and($snapshot->error)->toBe(ReadingError::PasswordUnreadable)
        ->and($state->error)->toBe(ReadingError::PasswordUnreadable)
        ->and($state->error_detail)->toBeNull()
        ->and($this->environment->fresh()->last_polled_at->toDateTimeString())->toBe('2026-09-17 10:00:00');

    Exceptions::assertNothingReported();
});

test('a job whose password no longer decrypts does not fail, and opens the unreachable alert in time', function () {
    DB::table('environments')->where('id', $this->environment->id)->update(['basic_auth_password' => AnotherAppKey::encrypt('super-secret-value')]);

    foreach (range(1, 9) as $poll) {
        app()->forgetScopedInstances();
        PollEnvironmentJob::dispatchSync($this->environment->id);
        $this->travel(15)->seconds();
    }

    expect(EnvironmentSnapshot::query()->count())->toBe(9)
        ->and(EnvironmentSnapshot::query()->pluck('error')->unique()->all())->toBe([ReadingError::PasswordUnreadable])
        ->and(Alert::query()->sole()->metric)->toBe(AlertRuleMetric::EndpointUnreachable)
        ->and(DB::table('failed_jobs')->count())->toBe(0);
});

test('the breaches of the evaluation are stored with a degraded status', function () {
    $this->reader->results = [($this->reading)([
        'workload' => [new HorizonQueueLoad(name: 'default', length: 2500, wait: 90, processes: 7)],
        'pendingJobs' => [
            new HorizonPendingJob(name: 'App\\Jobs\\BuildReport', queue: 'reports', status: 'reserved', reservedAt: CarbonImmutable::parse('2026-09-17 09:50:00')),
        ],
    ])];

    $snapshot = ($this->poll)();

    expect($snapshot->status)->toBe(EnvironmentStatus::Degraded)
        ->and($snapshot->breaches->all())->toEqualCanonicalizing([
            AlertRuleMetric::QueuePending,
            AlertRuleMetric::QueueMaxWait,
            AlertRuleMetric::JobRuntime,
        ])
        ->and(EnvironmentState::query()->sole()->status)->toBe(EnvironmentStatus::Degraded);

    $raw = DB::table('environment_snapshots')->value('breaches');

    expect(json_decode($raw, true))->toEqualCanonicalizing(['queue.pending', 'queue.max_wait', 'job.runtime']);
});

test('the pending total of several saturated queues is saturated too, so the snapshot is still written', function () {
    $this->reader->results = [($this->reading)([
        'workload' => [
            new HorizonQueueLoad(name: 'default', length: 2147483647, wait: 0, processes: 1),
            new HorizonQueueLoad(name: 'emails', length: 2147483647, wait: 0, processes: 1),
        ],
    ])];

    $snapshot = ($this->poll)();

    expect($snapshot->pending)->toBe(2147483647)
        ->and(DB::table('environment_snapshots')->value('pending'))->toBe(2147483647);
});

test('last_polled_at moves with every reading, failed or not, and updated_at does not', function () {
    $updatedAt = $this->environment->fresh()->updated_at->toDateTimeString();

    $this->reader->results = [($this->reading)(), new HorizonReadFailed(ReadingError::Unreachable)];

    $this->travel(5)->minutes();
    ($this->poll)();

    expect($this->environment->fresh()->last_polled_at->toDateTimeString())->toBe('2026-09-17 10:05:00')
        ->and($this->environment->last_polled_at->toDateTimeString())->toBe('2026-09-17 10:05:00');

    $this->travel(15)->seconds();
    ($this->poll)();

    $fresh = $this->environment->fresh();

    expect($fresh->last_polled_at->toDateTimeString())->toBe('2026-09-17 10:05:15')
        ->and($fresh->updated_at->toDateTimeString())->toBe($updatedAt);
});

test('the reader gets the decrypted password, and no stored row or queued payload contains it', function () {
    $this->reader->results = [($this->reading)(), new HorizonReadFailed(ReadingError::Unauthorized)];

    ($this->poll)();
    ($this->poll)();

    expect($this->reader->passwords)->toBe(['super-secret-value', 'super-secret-value']);

    config(['queue.default' => 'database']);
    PollEnvironmentJob::dispatch($this->environment->id);

    $stored = json_encode([
        DB::table('environment_snapshots')->get(),
        DB::table('environment_states')->get(),
        DB::table('jobs')->get(),
    ]);

    expect(DB::table('jobs')->count())->toBe(1)
        ->and($stored)->not->toContain('super-secret-value')
        ->and(serialize(new PollEnvironmentJob($this->environment->id)))->not->toContain('super-secret-value')
        ->and(array_keys(get_object_vars(new PollEnvironmentJob($this->environment->id))))->toContain('environmentId', 'dispatchedAt');
});

test('a reader that breaks its contract counts as unreachable and is reported without its message', function () {
    Exceptions::fake();

    $this->reader->results = [new RuntimeException('cURL error 7 for https://monitor:super-secret-value@horizon.example.com/horizon/api/stats')];

    $snapshot = ($this->poll)();

    expect($snapshot->status)->toBe(EnvironmentStatus::Unreachable)
        ->and($snapshot->error)->toBe(ReadingError::Unreachable);

    Exceptions::assertReported(fn (RuntimeException $exception) => $exception->getPrevious() === null
        && str_contains($exception->getMessage(), RuntimeException::class)
        && str_contains($exception->getMessage(), 'PollEnvironmentTest.php:')
        && ! str_contains($exception->getMessage(), 'super-secret-value'));
});

test('the job reads an enabled environment', function () {
    $this->reader->results = [($this->reading)()];

    PollEnvironmentJob::dispatchSync($this->environment->id);

    expect($this->reader->passwords)->toHaveCount(1)
        ->and(EnvironmentSnapshot::query()->count())->toBe(1);
});

test('a job older than the interval drops itself', function () {
    PollEnvironmentJob::dispatchSync($this->environment->id, now()->getTimestamp() - 16);

    expect($this->reader->passwords)->toBe([])
        ->and(EnvironmentSnapshot::query()->count())->toBe(0);
});

test('a job exactly one interval old still reads', function () {
    $this->reader->results = [($this->reading)()];

    PollEnvironmentJob::dispatchSync($this->environment->id, now()->getTimestamp() - 15);

    expect($this->reader->passwords)->toHaveCount(1);
});

test('a job whose environment was read since its dispatch drops itself', function () {
    $this->environment->forceFill(['last_polled_at' => now()->subSeconds(3)])->save();

    PollEnvironmentJob::dispatchSync($this->environment->id, now()->getTimestamp() - 3);

    expect($this->reader->passwords)->toBe([])
        ->and(EnvironmentSnapshot::query()->count())->toBe(0);
});

test('a job whose environment was last read before its dispatch reads', function () {
    $this->reader->results = [($this->reading)()];
    $this->environment->forceFill(['last_polled_at' => now()->subSeconds(4)])->save();

    PollEnvironmentJob::dispatchSync($this->environment->id, now()->getTimestamp() - 3);

    expect($this->reader->passwords)->toHaveCount(1);
});

test('the job does not read a paused environment', function () {
    $this->environment->update(['polling_enabled' => false]);

    PollEnvironmentJob::dispatchSync($this->environment->id);

    expect($this->reader->passwords)->toBe([])
        ->and(EnvironmentSnapshot::query()->count())->toBe(0)
        ->and(EnvironmentState::query()->count())->toBe(0);
});

test('the job does not read a deleted environment and does not fail', function () {
    $id = $this->environment->id;
    $this->environment->delete();

    PollEnvironmentJob::dispatchSync($id);

    expect($this->reader->passwords)->toBe([])
        ->and(EnvironmentSnapshot::query()->count())->toBe(0);
});

test('the job is unique per environment, never retried and bounded in time', function () {
    Queue::fake();

    $other = Environment::factory()->create();

    PollEnvironmentJob::dispatch($this->environment->id);
    PollEnvironmentJob::dispatch($this->environment->id);
    PollEnvironmentJob::dispatch($other->id);

    Queue::assertPushed(PollEnvironmentJob::class, 2);
    Queue::assertPushed(PollEnvironmentJob::class, fn (PollEnvironmentJob $job) => $job->environmentId === $this->environment->id);
    Queue::assertPushed(PollEnvironmentJob::class, fn (PollEnvironmentJob $job) => $job->environmentId === $other->id);

    $job = new PollEnvironmentJob($this->environment->id);

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe((string) $this->environment->id)
        ->and($job->tries)->toBe(1)
        ->and($job->timeout)->toBe(30)
        ->and($job->uniqueFor)->toBe(60);
});

test('a job dropped for its age says so in the log', function () {
    Log::spy();

    PollEnvironmentJob::dispatchSync($this->environment->id, now()->getTimestamp() - 40);

    Log::shouldHaveReceived('warning')->once()->with(
        'A poll waited in the queue longer than its interval and was dropped.',
        ['environment' => $this->environment->id, 'waited_seconds' => 40],
    );
});

test('a job killed by its timeout stores an unreachable reading that says so', function () {
    (new PollEnvironmentJob($this->environment->id))->failed(new TimeoutExceededException('Poll has timed out.'));

    $snapshot = EnvironmentSnapshot::query()->sole();
    $state = EnvironmentState::query()->sole();

    expect($snapshot->status)->toBe(EnvironmentStatus::Unreachable)
        ->and($snapshot->error)->toBe(ReadingError::Unreachable)
        ->and($state->status)->toBe(EnvironmentStatus::Unreachable)
        ->and($state->error_detail)->toBe(['cause' => ReadingCause::Timeout->value, 'status' => null, 'location' => null])
        ->and($this->environment->fresh()->last_polled_at->toDateTimeString())->toBe('2026-09-17 10:00:00');
});

test('a job that throws stores an unreachable reading blaming the poll, and still fails', function () {
    Exceptions::fake();

    $this->reader->results = [($this->reading)()];

    $thrown = false;
    EnvironmentSnapshot::creating(function () use (&$thrown) {
        if (! $thrown) {
            $thrown = true;

            throw new RuntimeException('The reading could not be stored.');
        }
    });

    expect(fn () => PollEnvironmentJob::dispatchSync($this->environment->id))->toThrow(RuntimeException::class, 'The reading could not be stored.');

    expect(EnvironmentSnapshot::query()->sole()->error)->toBe(ReadingError::Unreachable)
        ->and(EnvironmentState::query()->sole()->error_detail['cause'] ?? null)->toBe(ReadingCause::PollFailed->value);
});

test('polls killed in a row open the unreachable alert like failed readings do', function () {
    $kill = function () {
        app()->forgetScopedInstances();
        (new PollEnvironmentJob($this->environment->id))->failed(new TimeoutExceededException('Poll has timed out.'));
    };

    $kill();

    foreach (range(1, 7) as $ignored) {
        $this->travel(15)->seconds();
        $kill();
    }

    expect(Alert::query()->count())->toBe(0);

    $this->travel(15)->seconds();
    $kill();

    expect(Alert::query()->sole()->metric)->toBe(AlertRuleMetric::EndpointUnreachable);
});

test('a failed job of a paused or deleted environment stores nothing', function (Closure $leave) {
    $leave($this->environment);

    (new PollEnvironmentJob($this->environment->id))->failed(new RuntimeException('Boom.'));

    expect(EnvironmentSnapshot::query()->count())->toBe(0)
        ->and(EnvironmentState::query()->count())->toBe(0);
})->with([
    'paused' => [fn (Environment $environment) => $environment->update(['polling_enabled' => false])],
    'deleted' => [fn (Environment $environment) => $environment->delete()],
]);

test('a failed job that a newer reading has replaced stores nothing', function () {
    $job = new PollEnvironmentJob($this->environment->id);

    $this->travel(20)->seconds();
    $this->environment->forceFill(['last_polled_at' => now()])->save();

    $this->travel(70)->seconds();
    $job->failed(new MaxAttemptsExceededException('Poll has been attempted too many times.'));

    expect(EnvironmentSnapshot::query()->count())->toBe(0)
        ->and(EnvironmentState::query()->count())->toBe(0)
        ->and($this->environment->fresh()->last_polled_at->toDateTimeString())->toBe('2026-09-17 10:00:20');
});

test('a failed job older than its interval and timeout stores nothing', function () {
    $job = new PollEnvironmentJob($this->environment->id);

    $this->travel($this->environment->poll_interval_seconds + 31)->seconds();
    $job->failed(new MaxAttemptsExceededException('Poll has been attempted too many times.'));

    expect(EnvironmentSnapshot::query()->count())->toBe(0)
        ->and(EnvironmentState::query()->count())->toBe(0);
});

test('a job killed at the end of its wait and timeout still stores its reading', function () {
    $job = new PollEnvironmentJob($this->environment->id);

    $this->travel($this->environment->poll_interval_seconds + 30)->seconds();
    $job->failed(new TimeoutExceededException('Poll has timed out.'));

    expect(EnvironmentSnapshot::query()->sole()->error)->toBe(ReadingError::Unreachable);
});

test('a failed job that cannot store its reading reports it without throwing', function () {
    Exceptions::fake();

    $this->mock(PollEnvironment::class)->shouldReceive('recordFailure')->andThrow(new RuntimeException('Database is gone for https://monitor:super-secret-value@example.com'));

    (new PollEnvironmentJob($this->environment->id))->failed(new RuntimeException('Boom.'));

    Exceptions::assertReported(fn (RuntimeException $exception) => str_starts_with($exception->getMessage(), 'Recording a failed poll threw '.RuntimeException::class)
        && ! str_contains($exception->getMessage(), 'super-secret-value'));
});

test('the state remembers since when its status holds, across readings of the same status', function () {
    $this->reader->results = [($this->reading)(), ($this->reading)(['latencyMs' => 60])];

    ($this->poll)();

    expect(EnvironmentState::query()->sole()->status_since->toDateTimeString())->toBe('2026-09-17 10:00:00');

    $this->travel(15)->seconds();
    ($this->poll)();

    $state = EnvironmentState::query()->sole();

    expect($state->captured_at->toDateTimeString())->toBe('2026-09-17 10:00:15')
        ->and($state->latency_ms)->toBe(60)
        ->and($state->status_since->toDateTimeString())->toBe('2026-09-17 10:00:00');
});

test('a state that never knew its start gets one from the next reading of the same status', function () {
    EnvironmentState::factory()->for($this->environment)->create([
        'captured_at' => '2026-09-17 09:59:45',
        'status' => EnvironmentStatus::Active,
    ]);
    DB::table('environment_states')->update(['status_since' => null]);
    $this->reader->results = [($this->reading)()];

    ($this->poll)();

    expect(EnvironmentState::query()->sole()->status_since->toDateTimeString())->toBe('2026-09-17 10:00:00');
});

test('a change of status moves the start of the run to the new reading', function () {
    $this->reader->results = [
        ($this->reading)(),
        ($this->reading)(['workload' => [new HorizonQueueLoad(name: 'default', length: 2500, wait: 1, processes: 7)]]),
        new HorizonReadFailed(ReadingError::Unreachable),
    ];

    ($this->poll)();
    $this->travel(15)->seconds();
    ($this->poll)();

    expect(EnvironmentState::query()->sole()->status)->toBe(EnvironmentStatus::Degraded)
        ->and(EnvironmentState::query()->sole()->status_since->toDateTimeString())->toBe('2026-09-17 10:00:15');

    $this->travel(15)->seconds();
    ($this->poll)();

    expect(EnvironmentState::query()->sole()->status)->toBe(EnvironmentStatus::Unreachable)
        ->and(EnvironmentState::query()->sole()->status_since->toDateTimeString())->toBe('2026-09-17 10:00:30');
});

test('failed readings in a row keep the start of the unreachable run', function () {
    $this->reader->results = [
        new HorizonReadFailed(ReadingError::Unreachable),
        new HorizonReadFailed(ReadingError::Unauthorized),
        ($this->reading)(),
    ];

    ($this->poll)();

    expect(EnvironmentState::query()->sole()->status_since->toDateTimeString())->toBe('2026-09-17 10:00:00');

    $this->travel(45)->seconds();
    ($this->poll)();

    $state = EnvironmentState::query()->sole();

    expect($state->error)->toBe(ReadingError::Unauthorized)
        ->and($state->captured_at->toDateTimeString())->toBe('2026-09-17 10:00:45')
        ->and($state->status_since->toDateTimeString())->toBe('2026-09-17 10:00:00');

    $this->travel(15)->seconds();
    ($this->poll)();

    expect(EnvironmentState::query()->sole()->status)->not->toBe(EnvironmentStatus::Unreachable)
        ->and(EnvironmentState::query()->sole()->status_since->toDateTimeString())->toBe('2026-09-17 10:01:00');
});

test('a late reading moves neither the status nor the start of the run', function () {
    $this->reader->results = [
        new HorizonReadFailed(ReadingError::Unreachable),
        ($this->reading)(),
    ];

    $this->travelTo(CarbonImmutable::parse('2026-09-17 10:00:15'));
    ($this->poll)();

    $this->travelTo(CarbonImmutable::parse('2026-09-17 10:00:00'));
    ($this->poll)();

    $state = EnvironmentState::query()->sole();

    expect($state->status)->toBe(EnvironmentStatus::Unreachable)
        ->and($state->status_since->toDateTimeString())->toBe('2026-09-17 10:00:15');
});

test('the thresholds of the team decide the status of a reading', function () {
    AlertRule::factory()->for($this->environment->application->team)->create([
        'scope' => 'organization',
        'metric' => AlertRuleMetric::QueuePending,
        'threshold' => 10,
        'severity' => null,
        'notify_email' => null,
        'enabled' => null,
    ]);

    $this->reader->results = [($this->reading)()];

    $snapshot = ($this->poll)();

    expect($snapshot->status)->toBe(EnvironmentStatus::Degraded)
        ->and($snapshot->breaches->all())->toBe([AlertRuleMetric::QueuePending])
        ->and(EnvironmentState::query()->sole()->status)->toBe(EnvironmentStatus::Degraded);
});

test('a rule disabled for the name of the environment does not degrade it', function () {
    AlertRule::factory()->for($this->environment->application->team)->create([
        'scope' => 'Production',
        'metric' => AlertRuleMetric::QueuePending,
        'threshold' => null,
        'severity' => null,
        'notify_email' => null,
        'enabled' => false,
    ]);

    $this->reader->results = [($this->reading)([
        'workload' => [new HorizonQueueLoad(name: 'default', length: 2500, wait: 1, processes: 7)],
    ])];

    $snapshot = ($this->poll)();

    expect($snapshot->status)->toBe(EnvironmentStatus::Active)
        ->and($snapshot->breaches->all())->toBe([]);
});

test('the oldest pending jobs are asked for only while the job-runtime rule is enabled', function () {
    $this->reader->results = [($this->reading)(), ($this->reading)()];

    ($this->poll)();

    AlertRule::factory()->for($this->environment->application->team)->create([
        'scope' => 'organization',
        'metric' => AlertRuleMetric::JobRuntime,
        'threshold' => null,
        'severity' => null,
        'notify_email' => null,
        'enabled' => false,
    ]);

    $this->app->forgetScopedInstances();
    $this->travel(15)->seconds();
    ($this->poll)();

    expect(array_column($this->reader->calls, 'oldestPendingJobs'))->toBe([true, false]);
});

test('runtimes fetched by a reading are reused until they are five minutes old', function () {
    $this->reader->results = [
        ($this->reading)(['runtimesFetched' => true]),
        ($this->reading)(['queueRuntimes' => ['default' => 0.4, 'reports' => 2.5]]),
        ($this->reading)(['runtimesFetched' => true, 'queueRuntimes' => ['default' => 0.9]]),
    ];

    ($this->poll)();
    $this->travel(299)->seconds();
    ($this->poll)();
    $this->travel(1)->seconds();
    ($this->poll)();

    $state = EnvironmentState::query()->sole();

    expect(array_column($this->reader->calls, 'knownRuntimes'))->toBe([
        null,
        ['default' => 0.4, 'emails' => null, 'reports' => 2.5, 'archive' => null],
        null,
    ])
        ->and($state->runtimes_fetched_at?->toDateTimeString())->toBe('2026-09-17 10:05:00')
        ->and(array_column($state->queues, 'runtimeSeconds', 'name'))->toBe(['default' => 0.9, 'emails' => null, 'reports' => null, 'archive' => null]);
});

test('a reading that reused the runtimes keeps the time they were fetched', function () {
    $this->reader->results = [($this->reading)(['runtimesFetched' => true]), ($this->reading)()];

    ($this->poll)();
    $this->travel(60)->seconds();
    ($this->poll)();

    expect(EnvironmentState::query()->sole()->runtimes_fetched_at?->toDateTimeString())->toBe('2026-09-17 10:00:00');
});

test('a failed reading stores what went wrong next to its reason, and the next reading clears it', function () {
    $this->reader->results = [
        new HorizonReadFailed(ReadingError::NotHorizon, ReadingCause::Redirect, 302, 'https://shop.example.com/login'),
        ($this->reading)(),
    ];

    ($this->poll)();

    expect(EnvironmentState::query()->sole()->error_detail)->toBe(['cause' => 'redirect', 'status' => 302, 'location' => 'https://shop.example.com/login']);

    $this->travel(15)->seconds();
    ($this->poll)();

    expect(EnvironmentState::query()->sole()->error_detail)->toBeNull();
});

test('a failed-jobs list cut while every job is inside the hour is stored as a lower bound', function (bool $truncated, array $minutesAgo, bool $capped) {
    $jobs = array_map(fn (int $minutes) => new HorizonFailedJob(
        name: 'App\\Jobs\\Import',
        queue: 'default',
        exception: 'RuntimeException',
        attempts: 1,
        failedAt: CarbonImmutable::now()->subMinutes($minutes),
    ), $minutesAgo);

    $this->reader->results = [($this->reading)(['failedJobs' => $jobs, 'failedJobsTruncated' => $truncated])];

    $snapshot = ($this->poll)();

    expect($snapshot->failed_last_hour)->toBe(count(array_filter($minutesAgo, fn (int $minutes) => $minutes <= 60)))
        ->and($snapshot->failed_last_hour_capped)->toBe($capped);
})->with([
    'cut inside the hour' => [true, array_fill(0, 200, 5), true],
    'cut past the hour' => [true, [...array_fill(0, 150, 5), ...array_fill(0, 50, 90)], false],
    'complete' => [false, array_fill(0, 120, 5), false],
]);

test('a poll of a horizon with many queues maps each queue to its supervisor in one pass', function () {
    $supervisors = array_map(fn (int $s) => new HorizonSupervisor(
        name: "supervisor-{$s}",
        status: 'running',
        processes: array_fill_keys(array_map(fn (int $q) => "redis:q{$s}-{$q}", range(1, 500)), 1),
    ), range(1, 200));

    $workload = array_map(fn (int $q) => new HorizonQueueLoad(name: "missing-{$q}", length: 1, wait: 0, processes: 1), range(1, 499));
    $workload[] = new HorizonQueueLoad(name: 'q200-500', length: 1, wait: 0, processes: 1);

    $this->reader->results = [($this->reading)([
        'masters' => [new HorizonMaster(name: 'worker-1', status: 'running', supervisors: $supervisors)],
        'workload' => $workload,
    ])];

    $started = microtime(true);
    ($this->poll)();

    $queues = EnvironmentState::query()->sole()->queues;

    expect(microtime(true) - $started)->toBeLessThan(2.0)
        ->and($queues[0]['supervisor'])->toBeNull()
        ->and($queues[499]['supervisor'])->toBe('supervisor-200');
});
