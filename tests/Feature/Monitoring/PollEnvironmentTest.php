<?php

use App\Actions\Monitoring\PollEnvironment;
use App\Enums\AlertRuleMetric;
use App\Enums\EnvironmentStatus;
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
use App\Models\Environment;
use App\Models\EnvironmentSnapshot;
use App\Models\EnvironmentState;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-17 10:00:00'));

    // Stands in for lane A's client: hands out the queued results in order
    // and remembers the password of every target it was given.
    $this->reader = new class implements HorizonReader
    {
        /** @var list<HorizonReading|Throwable|Closure(): HorizonReading> */
        public array $results = [];

        /** @var list<string|null> */
        public array $passwords = [];

        public function read(HorizonTarget $target): HorizonReading
        {
            $this->passwords[] = $target->password();

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
                // Lists archive with no process: not its supervisor.
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
        ->and($snapshot->failed_last_24_hours)->toBe(5)
        ->and($snapshot->failed_window_minutes)->toBe(10080)
        ->and($snapshot->workers)->toBe(12)
        ->and($snapshot->node_count)->toBe(2)
        ->and($snapshot->latency_ms)->toBe(42);

    $state = EnvironmentState::query()->sole();

    expect($state->environment_id)->toBe($this->environment->id)
        ->and($state->captured_at->toDateTimeString())->toBe('2026-09-17 10:00:00')
        ->and($state->status)->toBe(EnvironmentStatus::Active)
        ->and($state->error)->toBeNull()
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
        // Secondary sections failing do not make the masters any less read.
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

    // The second master was not listed: Horizon drops a master after 15
    // seconds without a heartbeat, so it is gone rather than kept.
    expect(array_column($afterRecovery->nodes, 'seenAt', 'hostname'))->toBe([
        'worker-1.example.com' => '2026-09-17T10:00:30+00:00',
    ]);
});

test('the failed-jobs window of a failed reading is the one of the reading before it', function () {
    $this->reader->results = [
        new HorizonReadFailed(ReadingError::Unreachable),
        ($this->reading)(),
        new HorizonReadFailed(ReadingError::Unreachable),
        new HorizonReadFailed(ReadingError::Unauthorized),
    ];

    // Nothing to carry yet: Horizon's own default.
    expect(($this->poll)()->failed_window_minutes)->toBe(1440);

    $this->travel(15)->seconds();
    expect(($this->poll)()->failed_window_minutes)->toBe(10080);

    $this->travel(15)->seconds();
    expect(($this->poll)()->failed_window_minutes)->toBe(10080);

    // Carried along the outage, not only from the last good reading.
    $this->travel(15)->seconds();
    expect(($this->poll)()->failed_window_minutes)->toBe(10080);

    // Another environment's window is not borrowed.
    $other = Environment::factory()->create();
    $this->reader->results = [new HorizonReadFailed(ReadingError::Unreachable)];

    expect(app(PollEnvironment::class)->handle($other)->failed_window_minutes)->toBe(1440);
});

test('a first reading that fails creates an empty state', function () {
    $this->reader->results = [new HorizonReadFailed(ReadingError::NotHorizon)];

    ($this->poll)();

    $state = EnvironmentState::query()->sole();

    expect($state->status)->toBe(EnvironmentStatus::Unreachable)
        ->and($state->error)->toBe(ReadingError::NotHorizon)
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

    // The older reading is stored second.
    $this->travelTo(CarbonImmutable::parse('2026-09-17 10:00:00'));
    $late = ($this->poll)();

    $state = EnvironmentState::query()->sole();

    expect($state->captured_at->toDateTimeString())->toBe('2026-09-17 10:00:15')
        ->and($state->latency_ms)->toBe(99)
        ->and($state->queues)->toHaveCount(4)
        ->and($this->environment->fresh()->last_polled_at->toDateTimeString())->toBe('2026-09-17 10:00:15')
        ->and($this->environment->last_polled_at->toDateTimeString())->toBe('2026-09-17 10:00:15')
        // The snapshot is history and is kept.
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

test('a password that no longer decrypts fails loudly and is not blamed on Horizon', function () {
    Exceptions::fake();

    DB::table('environments')->where('id', $this->environment->id)->update(['basic_auth_password' => 'not-an-encrypted-value']);

    expect(fn () => app(PollEnvironment::class)->handle($this->environment->fresh()))
        ->toThrow(DecryptException::class);

    expect($this->reader->passwords)->toBe([])
        ->and(EnvironmentSnapshot::query()->count())->toBe(0);

    Exceptions::assertNothingReported();
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
