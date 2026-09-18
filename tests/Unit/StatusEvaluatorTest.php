<?php

use App\Alerts\EffectiveRule;
use App\Alerts\RuleSet;
use App\Enums\AlertRuleMetric;
use App\Enums\AlertSeverity;
use App\Enums\EnvironmentStatus;
use App\Enums\RuleOrigin;
use App\Externals\Horizon\Data\HorizonFailedJob;
use App\Externals\Horizon\Data\HorizonMaster;
use App\Externals\Horizon\Data\HorizonPendingJob;
use App\Externals\Horizon\Data\HorizonQueueLoad;
use App\Externals\Horizon\Data\HorizonStats;
use App\Externals\Horizon\Data\HorizonSupervisor;
use App\Externals\Horizon\HorizonReading;
use App\Monitoring\StatusEvaluator;
use Carbon\CarbonImmutable;

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
 * @param  list<HorizonMaster>|null  $masters
 * @param  list<HorizonQueueLoad>|null  $workload
 * @param  list<HorizonPendingJob>|null  $pendingJobs
 * @param  list<HorizonFailedJob>|null  $failed
 */
function evaluatorReading(
    string $status = 'running',
    int $failedJobs = 0,
    ?array $masters = null,
    ?array $workload = null,
    ?array $pendingJobs = [],
    int $failedWindowMinutes = 1440,
    ?array $failed = [],
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
        failedJobs: $failed,
        pendingJobs: $pendingJobs,
        queueRuntimes: ['default' => 0.4],
        latencyMs: 35,
    );
}

/**
 * @return list<HorizonFailedJob>
 */
function failedJobsAgo(int $count, int $secondsAgo = 60): array
{
    return array_map(fn () => new HorizonFailedJob(
        name: 'App\\Jobs\\SendEmail',
        queue: 'emails',
        exception: 'RuntimeException: boom',
        attempts: 1,
        failedAt: CarbonImmutable::now()->subSeconds($secondsAgo),
    ), range(1, $count));
}

/**
 * @param  array<string, array{threshold?: float, enabled?: bool}>  $changes
 */
function evaluatorRules(array $changes): RuleSet
{
    $rules = [];

    foreach (RuleSet::defaults()->rules as $key => $rule) {
        $rules[$key] = new EffectiveRule(
            metric: $rule->metric,
            threshold: $changes[$key]['threshold'] ?? $rule->threshold,
            severity: AlertSeverity::Critical,
            notifyByEmail: false,
            enabled: $changes[$key]['enabled'] ?? $rule->enabled,
            thresholdOrigin: RuleOrigin::Override,
            severityOrigin: RuleOrigin::Override,
            notifyOrigin: RuleOrigin::Override,
            enabledOrigin: RuleOrigin::Override,
        );
    }

    return new RuleSet($rules);
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
    $evaluated = (new StatusEvaluator(60))->evaluate($reading, RuleSet::defaults());

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
        evaluatorReading(status: 'paused', masters: [], failed: failedJobsAgo(50)),
        EnvironmentStatus::Inactive,
        [AlertRuleMetric::HorizonMasterInactive],
    ],

    'horizon reports itself paused' => fn () => [
        evaluatorReading(status: 'paused'),
        EnvironmentStatus::Paused,
        [AlertRuleMetric::HorizonPaused],
    ],
    'every master is paused' => fn () => [
        evaluatorReading(masters: [evaluatorMaster('paused'), evaluatorMaster('paused')]),
        EnvironmentStatus::Paused,
        [AlertRuleMetric::HorizonPaused],
    ],
    'paused still measures the thresholds, and stays paused' => fn () => [
        evaluatorReading(status: 'paused', failed: failedJobsAgo(50), workload: [
            new HorizonQueueLoad(name: 'default', length: 50_000, wait: 900, processes: 0),
        ]),
        EnvironmentStatus::Paused,
        [AlertRuleMetric::HorizonPaused, AlertRuleMetric::QueuePending, AlertRuleMetric::QueueMaxWait, AlertRuleMetric::JobsFailedPerHour],
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

    'failed in the last hour at the threshold' => fn () => [
        evaluatorReading(failed: failedJobsAgo(20)),
        EnvironmentStatus::Active,
        [],
    ],
    'failed in the last hour above the threshold' => fn () => [
        evaluatorReading(failed: failedJobsAgo(21)),
        EnvironmentStatus::Degraded,
        [AlertRuleMetric::JobsFailedPerHour],
    ],
    'a job that failed exactly an hour ago still counts' => fn () => [
        evaluatorReading(failed: [...failedJobsAgo(20), ...failedJobsAgo(1, secondsAgo: 3600)]),
        EnvironmentStatus::Degraded,
        [AlertRuleMetric::JobsFailedPerHour],
    ],
    'a job that failed more than an hour ago does not count' => fn () => [
        evaluatorReading(failed: [...failedJobsAgo(20), ...failedJobsAgo(30, secondsAgo: 3601)]),
        EnvironmentStatus::Active,
        [],
    ],
    'a job dated after the reading counts' => fn () => [
        evaluatorReading(failed: [...failedJobsAgo(20), ...failedJobsAgo(1, secondsAgo: -30)]),
        EnvironmentStatus::Degraded,
        [AlertRuleMetric::JobsFailedPerHour],
    ],
    'a burst is not diluted over the window Horizon counts in' => fn () => [
        evaluatorReading(failedJobs: 21, failedWindowMinutes: 10080, failed: failedJobsAgo(21)),
        EnvironmentStatus::Degraded,
        [AlertRuleMetric::JobsFailedPerHour],
    ],
    'the count over the window Horizon states does not decide' => fn () => [
        evaluatorReading(failedJobs: 10_000, failedWindowMinutes: 30, failed: failedJobsAgo(3)),
        EnvironmentStatus::Active,
        [],
    ],
    'failed jobs that could not be read do not count' => fn () => [
        evaluatorReading(failed: null),
        EnvironmentStatus::Active,
        [],
    ],
    'a full page of failed jobs within the hour breaches' => fn () => [
        evaluatorReading(failed: failedJobsAgo(50)),
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
            failed: failedJobsAgo(21),
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

test('a failed reading is unreachable with the endpoint breach', function () {
    $evaluated = (new StatusEvaluator(60))->failed();

    expect($evaluated->status)->toBe(EnvironmentStatus::Unreachable)
        ->and($evaluated->breaches)->toBe([AlertRuleMetric::EndpointUnreachable]);
});

test('the failed jobs of the last hour are counted', function () {
    $evaluated = (new StatusEvaluator(60))->evaluate(evaluatorReading(
        failed: [...failedJobsAgo(7), ...failedJobsAgo(4, secondsAgo: 4000)],
    ), RuleSet::defaults());

    expect($evaluated->failedLastHour)->toBe(7);
});

test('a reading whose failed jobs could not be read counts none', function () {
    expect((new StatusEvaluator(60))->evaluate(evaluatorReading(failed: null), RuleSet::defaults())->failedLastHour)->toBe(0);
});

test('a failed reading counts no failed jobs', function () {
    expect((new StatusEvaluator(60))->failed()->failedLastHour)->toBe(0);
});

test('a reading is evaluated against the rules it is given', function (HorizonReading $reading, array $changes, EnvironmentStatus $status, array $breaches) {
    $evaluated = (new StatusEvaluator(60))->evaluate($reading, evaluatorRules($changes));

    expect($evaluated->status)->toBe($status)
        ->and($evaluated->breaches)->toBe($breaches);
})->with([
    'a lower pending threshold' => fn () => [
        evaluatorReading(workload: [new HorizonQueueLoad(name: 'default', length: 11, wait: 1, processes: 3)]),
        ['queue.pending' => ['threshold' => 10.0]],
        EnvironmentStatus::Degraded,
        [AlertRuleMetric::QueuePending],
    ],
    'a higher pending threshold' => fn () => [
        evaluatorReading(workload: [new HorizonQueueLoad(name: 'default', length: 4000, wait: 1, processes: 3)]),
        ['queue.pending' => ['threshold' => 5000.0]],
        EnvironmentStatus::Active,
        [],
    ],
    'a lower max wait threshold' => fn () => [
        evaluatorReading(workload: [new HorizonQueueLoad(name: 'default', length: 1, wait: 31, processes: 3)]),
        ['queue.max_wait' => ['threshold' => 30.0]],
        EnvironmentStatus::Degraded,
        [AlertRuleMetric::QueueMaxWait],
    ],
    'a lower failed-rate threshold' => fn () => [
        evaluatorReading(failed: failedJobsAgo(3)),
        ['jobs.failed_per_hour' => ['threshold' => 2.0]],
        EnvironmentStatus::Degraded,
        [AlertRuleMetric::JobsFailedPerHour],
    ],
    'a lower workers threshold' => fn () => [
        evaluatorReading(workload: [new HorizonQueueLoad(name: 'default', length: 1, wait: 1, processes: 0)]),
        ['workers.missing' => ['threshold' => 1.0]],
        EnvironmentStatus::Degraded,
        [AlertRuleMetric::WorkersMissing],
    ],
    'a higher runtime threshold' => fn () => [
        evaluatorReading(pendingJobs: [reservedJob(600)]),
        ['job.runtime' => ['threshold' => 900.0]],
        EnvironmentStatus::Active,
        [],
    ],
    'a disabled threshold rule never breaches' => fn () => [
        evaluatorReading(failed: failedJobsAgo(50), workload: [new HorizonQueueLoad(name: 'default', length: 50_000, wait: 1, processes: 3)]),
        ['queue.pending' => ['enabled' => false]],
        EnvironmentStatus::Degraded,
        [AlertRuleMetric::JobsFailedPerHour],
    ],
    'every breached rule disabled leaves the environment active' => fn () => [
        evaluatorReading(pendingJobs: [reservedJob(600)], workload: [new HorizonQueueLoad(name: 'default', length: 1, wait: 900, processes: 3)]),
        ['queue.max_wait' => ['enabled' => false], 'job.runtime' => ['enabled' => false]],
        EnvironmentStatus::Active,
        [],
    ],
    'a disabled inactive rule keeps the status and drops the breach' => fn () => [
        evaluatorReading(status: 'inactive'),
        ['horizon.master_inactive' => ['enabled' => false]],
        EnvironmentStatus::Inactive,
        [],
    ],
    'a disabled paused rule keeps the status and the other breaches' => fn () => [
        evaluatorReading(status: 'paused', failed: failedJobsAgo(50)),
        ['horizon.paused' => ['enabled' => false]],
        EnvironmentStatus::Paused,
        [AlertRuleMetric::JobsFailedPerHour],
    ],
    'the state rule thresholds do not decide the status' => fn () => [
        evaluatorReading(status: 'paused'),
        ['horizon.paused' => ['threshold' => 1440.0], 'horizon.master_inactive' => ['threshold' => 1440.0]],
        EnvironmentStatus::Paused,
        [AlertRuleMetric::HorizonPaused],
    ],
]);

test('a failed reading is unreachable with the endpoint breach and nothing else', function () {
    $failed = (new StatusEvaluator(60))->failed();

    expect($failed->status)->toBe(EnvironmentStatus::Unreachable)
        ->and($failed->breaches)->toBe([AlertRuleMetric::EndpointUnreachable])
        ->and($failed->failedLastHour)->toBe(0);
});
