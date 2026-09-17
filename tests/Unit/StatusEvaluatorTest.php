<?php

use App\Enums\AlertRuleMetric;
use App\Enums\EnvironmentStatus;
use App\Enums\ReadingError;
use App\Externals\Horizon\Data\HorizonMaster;
use App\Externals\Horizon\Data\HorizonPendingJob;
use App\Externals\Horizon\Data\HorizonQueueLoad;
use App\Externals\Horizon\Data\HorizonStats;
use App\Externals\Horizon\Data\HorizonSupervisor;
use App\Externals\Horizon\HorizonReading;
use App\Monitoring\StatusEvaluator;
use Carbon\CarbonImmutable;

// Runs without the application: no database, no container, no translator.

beforeEach(fn () => CarbonImmutable::setTestNow('2026-09-17 12:00:00'));

afterEach(fn () => CarbonImmutable::setTestNow());

function evaluatorMaster(string $status = 'running'): HorizonMaster
{
    return new HorizonMaster(
        name: 'worker-1',
        status: $status,
        supervisors: [new HorizonSupervisor(name: 'worker-1:supervisor-1', status: $status, processes: ['database:default' => 3])],
    );
}

/**
 * A healthy reading; each case overrides only what it is about.
 *
 * @param  list<HorizonMaster>|null  $masters
 * @param  list<HorizonQueueLoad>|null  $workload
 * @param  list<HorizonPendingJob>|null  $pendingJobs
 */
function evaluatorReading(
    string $status = 'running',
    int $failedJobs = 0,
    ?array $masters = null,
    ?array $workload = null,
    ?array $pendingJobs = [],
    int $failedWindowMinutes = 1440,
): HorizonReading {
    return new HorizonReading(
        stats: new HorizonStats(
            status: $status,
            jobsPerMinute: 120,
            failedJobs: $failedJobs,
            processes: 6,
            pausedMasters: 0,
            wait: ['database:default' => 2],
            failedJobsPeriodMinutes: $failedWindowMinutes,
        ),
        masters: $masters ?? [evaluatorMaster()],
        workload: $workload ?? [
            new HorizonQueueLoad(name: 'default', length: 10, wait: 2, processes: 3),
            new HorizonQueueLoad(name: 'emails', length: 0, wait: 0, processes: 0),
        ],
        failedJobs: [],
        pendingJobs: $pendingJobs,
        queueRuntimes: ['default' => 0.4],
        latencyMs: 35,
    );
}

function reservedJob(int $secondsAgo, string $status = 'reserved'): HorizonPendingJob
{
    return new HorizonPendingJob(
        name: 'App\\Jobs\\BuildReport',
        queue: 'reports',
        status: $status,
        reservedAt: CarbonImmutable::now()->subSeconds($secondsAgo),
    );
}

test('a reading is evaluated in the order of the spec', function (HorizonReading $reading, EnvironmentStatus $status, array $breaches) {
    $evaluated = (new StatusEvaluator)->evaluate($reading);

    expect($evaluated->status)->toBe($status)
        ->and($evaluated->breaches)->toBe($breaches);
})->with([
    'healthy' => fn () => [evaluatorReading(), EnvironmentStatus::Active, []],

    'horizon reports itself inactive' => fn () => [
        evaluatorReading(status: 'inactive'),
        EnvironmentStatus::Inactive,
        [AlertRuleMetric::HorizonMasterInactive],
    ],
    'no master is listed' => fn () => [
        evaluatorReading(masters: []),
        EnvironmentStatus::Inactive,
        [AlertRuleMetric::HorizonMasterInactive],
    ],
    'inactive wins over paused and over thresholds' => fn () => [
        evaluatorReading(status: 'paused', masters: [], failedJobs: 10_000),
        EnvironmentStatus::Inactive,
        [AlertRuleMetric::HorizonMasterInactive],
    ],

    'horizon reports itself paused' => fn () => [
        evaluatorReading(status: 'paused'),
        EnvironmentStatus::Paused,
        [],
    ],
    'every master is paused' => fn () => [
        evaluatorReading(masters: [evaluatorMaster('paused'), evaluatorMaster('paused')]),
        EnvironmentStatus::Paused,
        [],
    ],
    'paused hides the thresholds' => fn () => [
        evaluatorReading(status: 'paused', failedJobs: 10_000),
        EnvironmentStatus::Paused,
        [],
    ],
    'one master of two paused is not paused' => fn () => [
        evaluatorReading(masters: [evaluatorMaster('paused'), evaluatorMaster()]),
        EnvironmentStatus::Active,
        [],
    ],

    'pending at the threshold' => fn () => [
        evaluatorReading(workload: [
            new HorizonQueueLoad(name: 'default', length: 1500, wait: 1, processes: 3),
            new HorizonQueueLoad(name: 'emails', length: 500, wait: 1, processes: 3),
        ]),
        EnvironmentStatus::Active,
        [],
    ],
    'pending above the threshold, summed across queues' => fn () => [
        evaluatorReading(workload: [
            new HorizonQueueLoad(name: 'default', length: 1500, wait: 1, processes: 3),
            new HorizonQueueLoad(name: 'emails', length: 501, wait: 1, processes: 3),
        ]),
        EnvironmentStatus::Degraded,
        [AlertRuleMetric::QueuePending],
    ],

    'max wait at the threshold' => fn () => [
        evaluatorReading(workload: [new HorizonQueueLoad(name: 'default', length: 5, wait: 60, processes: 3)]),
        EnvironmentStatus::Active,
        [],
    ],
    'max wait above the threshold' => fn () => [
        evaluatorReading(workload: [
            new HorizonQueueLoad(name: 'default', length: 5, wait: 3, processes: 3),
            new HorizonQueueLoad(name: 'reports', length: 5, wait: 61, processes: 3),
        ]),
        EnvironmentStatus::Degraded,
        [AlertRuleMetric::QueueMaxWait],
    ],

    'failed per hour at the threshold' => fn () => [
        evaluatorReading(failedJobs: 480),
        EnvironmentStatus::Active,
        [],
    ],
    'failed per hour above the threshold' => fn () => [
        evaluatorReading(failedJobs: 481),
        EnvironmentStatus::Degraded,
        [AlertRuleMetric::JobsFailedPerHour],
    ],

    // 20 an hour: 3,360 over seven days is on the threshold.
    'the same count over a seven-day window stays under the threshold' => fn () => [
        evaluatorReading(failedJobs: 481, failedWindowMinutes: 10080),
        EnvironmentStatus::Active,
        [],
    ],
    'failed per hour at the threshold over seven days' => fn () => [
        evaluatorReading(failedJobs: 3360, failedWindowMinutes: 10080),
        EnvironmentStatus::Active,
        [],
    ],
    'failed per hour above the threshold over seven days' => fn () => [
        evaluatorReading(failedJobs: 3361, failedWindowMinutes: 10080),
        EnvironmentStatus::Degraded,
        [AlertRuleMetric::JobsFailedPerHour],
    ],
    'a window shorter than an hour scales the count up' => fn () => [
        evaluatorReading(failedJobs: 11, failedWindowMinutes: 30),
        EnvironmentStatus::Degraded,
        [AlertRuleMetric::JobsFailedPerHour],
    ],
    'a window of zero minutes does not divide by zero' => fn () => [
        evaluatorReading(failedJobs: 1, failedWindowMinutes: 0),
        EnvironmentStatus::Degraded,
        [AlertRuleMetric::JobsFailedPerHour],
    ],

    'queues without workers below the threshold' => fn () => [
        evaluatorReading(workload: [
            new HorizonQueueLoad(name: 'default', length: 1, wait: 1, processes: 0),
            new HorizonQueueLoad(name: 'emails', length: 1, wait: 1, processes: 0),
            new HorizonQueueLoad(name: 'reports', length: 1, wait: 1, processes: 0),
            new HorizonQueueLoad(name: 'exports', length: 0, wait: 0, processes: 0),
        ]),
        EnvironmentStatus::Active,
        [],
    ],
    'queues without workers reaching the threshold' => fn () => [
        evaluatorReading(workload: [
            new HorizonQueueLoad(name: 'default', length: 1, wait: 1, processes: 0),
            new HorizonQueueLoad(name: 'emails', length: 1, wait: 1, processes: 0),
            new HorizonQueueLoad(name: 'reports', length: 1, wait: 1, processes: 0),
            new HorizonQueueLoad(name: 'exports', length: 1, wait: 1, processes: 0),
        ]),
        EnvironmentStatus::Degraded,
        [AlertRuleMetric::WorkersMissing],
    ],

    'a reserved job at the runtime threshold' => fn () => [
        evaluatorReading(pendingJobs: [reservedJob(120)]),
        EnvironmentStatus::Active,
        [],
    ],
    'a reserved job past the runtime threshold' => fn () => [
        evaluatorReading(pendingJobs: [reservedJob(30), reservedJob(121)]),
        EnvironmentStatus::Degraded,
        [AlertRuleMetric::JobRuntime],
    ],
    'an old job that is only pending does not count' => fn () => [
        evaluatorReading(pendingJobs: [reservedJob(3600, status: 'pending')]),
        EnvironmentStatus::Active,
        [],
    ],
    'a reserved job without a reservation time does not count' => fn () => [
        evaluatorReading(pendingJobs: [new HorizonPendingJob(name: 'App\\Jobs\\SendEmail', queue: 'emails', status: 'reserved', reservedAt: null)]),
        EnvironmentStatus::Active,
        [],
    ],
    'pending jobs that could not be read do not count' => fn () => [
        evaluatorReading(pendingJobs: null),
        EnvironmentStatus::Active,
        [],
    ],

    'several thresholds at once, in enum order' => fn () => [
        evaluatorReading(
            failedJobs: 1000,
            workload: [new HorizonQueueLoad(name: 'default', length: 2500, wait: 90, processes: 3)],
            pendingJobs: [reservedJob(600)],
        ),
        EnvironmentStatus::Degraded,
        [
            AlertRuleMetric::QueuePending,
            AlertRuleMetric::QueueMaxWait,
            AlertRuleMetric::JobRuntime,
            AlertRuleMetric::JobsFailedPerHour,
        ],
    ],
]);

test('a failed reading is unreachable with the endpoint breach', function (ReadingError $error) {
    $evaluated = (new StatusEvaluator)->failed($error);

    expect($evaluated->status)->toBe(EnvironmentStatus::Unreachable)
        ->and($evaluated->breaches)->toBe([AlertRuleMetric::EndpointUnreachable]);
})->with(ReadingError::cases());
