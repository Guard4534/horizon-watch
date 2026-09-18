<?php

namespace App\Actions\Monitoring;

use App\Alerts\AlertEngine;
use App\Alerts\EffectiveRules;
use App\Enums\HorizonStatus;
use App\Enums\ReadingError;
use App\Externals\Horizon\Data\HorizonFailedJob;
use App\Externals\Horizon\Data\HorizonMaster;
use App\Externals\Horizon\Data\HorizonPendingJob;
use App\Externals\Horizon\Data\HorizonQueueLoad;
use App\Externals\Horizon\Data\HorizonStats;
use App\Externals\Horizon\Exceptions\HorizonReadFailed;
use App\Externals\Horizon\HorizonReader;
use App\Externals\Horizon\HorizonReading;
use App\Externals\Horizon\HorizonTarget;
use App\Models\Environment;
use App\Models\EnvironmentSnapshot;
use App\Models\EnvironmentState;
use App\Monitoring\EvaluatedStatus;
use App\Monitoring\StatusEvaluator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class PollEnvironment
{
    private const MAX_COUNT = 2147483647;

    public function __construct(
        private readonly HorizonReader $reader,
        private readonly StatusEvaluator $evaluator,
        private readonly EffectiveRules $rules,
        private readonly AlertEngine $alerts,
    ) {}

    public function handle(Environment $environment): ?EnvironmentSnapshot
    {
        $capturedAt = CarbonImmutable::now();

        $target = HorizonTarget::fromEnvironment($environment);

        try {
            $reading = $this->reader->read($target);
        } catch (HorizonReadFailed $exception) {
            return $this->storeFailure($environment, $capturedAt, $exception->reason);
        } catch (Throwable $exception) {
            report(new RuntimeException(sprintf(
                'The Horizon reader threw %s at %s:%d instead of HorizonReadFailed.',
                $exception::class,
                $exception->getFile(),
                $exception->getLine(),
            )));

            return $this->storeFailure($environment, $capturedAt, ReadingError::Unreachable);
        }

        return $this->storeReading($environment, $capturedAt, $reading);
    }

    private function storeReading(Environment $environment, CarbonImmutable $capturedAt, HorizonReading $reading): ?EnvironmentSnapshot
    {
        $evaluated = $this->evaluator->evaluate($reading->failedJobs === null
            ? new HorizonReading(
                stats: $reading->stats,
                masters: $reading->masters,
                workload: $reading->workload,
                failedJobs: $this->keptFailedJobs($environment),
                pendingJobs: $reading->pendingJobs,
                queueRuntimes: $reading->queueRuntimes,
                latencyMs: $reading->latencyMs,
            )
            : $reading, $this->rules->forEnvironment($environment), $capturedAt);

        $state = [
            'status' => $evaluated->status,
            'error' => null,
            'horizon_status' => HorizonStatus::tryFrom($reading->stats->status),
            'latency_ms' => $reading->latencyMs,
            'nodes' => array_map(fn (HorizonMaster $master) => $this->node($master, $capturedAt), $reading->masters),
            'queues' => array_map(fn (HorizonQueueLoad $queue) => $this->queue($queue, $reading), $reading->workload),
            'pending_jobs' => $reading->pendingJobs === null ? [] : $this->reservedJobs($reading->pendingJobs),
        ];

        $sectionsOnInsert = ['status_since' => $capturedAt];

        if ($reading->failedJobs === null) {
            $sectionsOnInsert['failed_jobs'] = [];
        } else {
            $state['failed_jobs'] = array_map($this->failedJob(...), $reading->failedJobs);
        }

        return $this->store($environment, $capturedAt, $evaluated, [
            'pending' => min(self::MAX_COUNT, array_sum(array_map(fn (HorizonQueueLoad $queue) => $queue->length, $reading->workload))),
            'max_wait_seconds' => max([0, ...array_map(fn (HorizonQueueLoad $queue) => $queue->wait, $reading->workload)]),
            'jobs_per_minute' => $reading->stats->jobsPerMinute,
            'failed_in_window' => $reading->stats->failedJobs,
            'failed_window_minutes' => $reading->stats->failedJobsPeriodMinutes,
            'failed_last_hour' => $evaluated->failedLastHour,
            'workers' => $reading->stats->processes,
            'node_count' => count($reading->masters),
            'latency_ms' => $reading->latencyMs,
        ], $state, $sectionsOnInsert);
    }

    private function storeFailure(Environment $environment, CarbonImmutable $capturedAt, ReadingError $error): ?EnvironmentSnapshot
    {
        $evaluated = $this->evaluator->failed();

        return $this->store($environment, $capturedAt, $evaluated, [
            'error' => $error,
            'pending' => 0,
            'max_wait_seconds' => 0,
            'jobs_per_minute' => 0,
            'failed_in_window' => 0,
            'failed_window_minutes' => $this->previousFailedWindow($environment),
            'failed_last_hour' => 0,
            'workers' => 0,
            'node_count' => 0,
            'latency_ms' => null,
        ], [
            'status' => $evaluated->status,
            'error' => $error,
            'latency_ms' => null,
            'pending_jobs' => [],
        ], [
            'status_since' => $capturedAt,
            'horizon_status' => null,
            'nodes' => [],
            'queues' => [],
            'failed_jobs' => [],
        ]);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $insertOnly
     */
    private function store(
        Environment $environment,
        CarbonImmutable $capturedAt,
        EvaluatedStatus $evaluated,
        array $snapshot,
        array $state,
        array $insertOnly,
    ): ?EnvironmentSnapshot {
        return DB::transaction(function () use ($environment, $capturedAt, $evaluated, $snapshot, $state, $insertOnly) {
            $address = DB::table('environments')->where('id', $environment->id)->lock('for share')->value('horizon_url');

            if ($address !== $environment->horizon_url) {
                return null;
            }

            $stored = EnvironmentSnapshot::query()->create([
                'environment_id' => $environment->id,
                'captured_at' => $capturedAt,
                'status' => $evaluated->status,
                'error' => null,
                'breaches' => $evaluated->breaches,
                ...$snapshot,
            ]);

            $current = $this->upsertState($environment, [
                'captured_at' => $capturedAt,
                ...$state,
            ], $insertOnly);

            if ($current !== null) {
                $this->alerts->afterReading($environment, $stored, $current);
            }

            $moved = Environment::query()
                ->whereKey($environment->id)
                ->where(fn ($query) => $query->whereNull('last_polled_at')->orWhere('last_polled_at', '<', $capturedAt))
                ->toBase()
                ->update(['last_polled_at' => $capturedAt]);

            if ($moved > 0) {
                $environment->forceFill(['last_polled_at' => $capturedAt])->syncOriginalAttribute('last_polled_at');
            }

            return $stored;
        });
    }

    private function previousFailedWindow(Environment $environment): int
    {
        $previous = EnvironmentSnapshot::query()
            ->select(['environment_id', 'failed_window_minutes'])
            ->whereRaw("(environment_id, captured_at) <= (?, 'infinity'::timestamptz)", [$environment->id])
            ->orderByDesc('environment_id')
            ->orderByDesc('captured_at')
            ->orderByDesc('id')
            ->first();

        return $previous !== null && $previous->environment_id === $environment->id
            ? $previous->failed_window_minutes
            : HorizonStats::DEFAULT_FAILED_WINDOW_MINUTES;
    }

    /**
     * @return list<HorizonFailedJob>
     */
    private function keptFailedJobs(Environment $environment): array
    {
        $jobs = EnvironmentState::query()
            ->where('environment_id', $environment->id)
            ->first(['failed_jobs'])
            ->failed_jobs ?? [];

        return array_map(fn (array $job) => new HorizonFailedJob(
            name: $job['job'],
            queue: $job['queue'],
            exception: $job['exception'],
            attempts: $job['tries'],
            failedAt: CarbonImmutable::parse($job['failedAt']),
        ), $jobs);
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $insertOnly
     */
    private function upsertState(Environment $environment, array $state, array $insertOnly): ?EnvironmentState
    {
        $model = new EnvironmentState;
        $timestamp = $model->freshTimestamp();

        $row = $model
            ->forceFill([
                'environment_id' => $environment->id,
                ...$insertOnly,
                ...$state,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ])
            ->getAttributes();

        $query = DB::table($model->getTable());
        $grammar = $query->getGrammar();

        $statusSince = new Expression(
            'case when "environment_states"."status" = excluded."status" and "environment_states"."captured_at" >= excluded."captured_at" - make_interval(secs => ?) then coalesce("environment_states"."status_since", excluded."status_since") else excluded."status_since" end',
        );

        $sql = $grammar->compileUpsert($query, [$row], ['environment_id'], [...array_keys($state), 'status_since' => $statusSince, 'updated_at'])
            .' where '.$grammar->wrap($model->getTable().'.captured_at').' <= '.$grammar->wrap('excluded.captured_at')
            .' returning *';

        $written = DB::selectOne($sql, [
            ...array_values($row),
            config()->integer('horizon-watch.stale_after_intervals') * $environment->poll_interval_seconds,
        ]);

        return $written === null ? null : $model->newFromBuilder((array) $written);
    }

    /**
     * @return array{hostname: string, status: string, workers: int, supervisors: int, queues: int, seenAt: string}
     */
    private function node(HorizonMaster $master, CarbonImmutable $capturedAt): array
    {
        $processes = [];
        $queues = [];

        foreach ($master->supervisors as $supervisor) {
            foreach ($supervisor->processes as $key => $count) {
                $processes[] = $count;
                $queues[$this->queueName($key)] = true;
            }
        }

        return [
            'hostname' => $master->name,
            'status' => $master->status === 'paused' ? 'paused' : 'running',
            'workers' => array_sum($processes),
            'supervisors' => count($master->supervisors),
            'queues' => count($queues),
            'seenAt' => $capturedAt->toIso8601String(),
        ];
    }

    /**
     * @return array{name: string, supervisor: string|null, workers: int, pending: int, waitSeconds: int, runtimeSeconds: float|null}
     */
    private function queue(HorizonQueueLoad $queue, HorizonReading $reading): array
    {
        return [
            'name' => $queue->name,
            'supervisor' => $this->supervisorOf($queue->name, $reading),
            'workers' => $queue->processes,
            'pending' => $queue->length,
            'waitSeconds' => $queue->wait,
            'runtimeSeconds' => $reading->queueRuntimes[$queue->name] ?? null,
        ];
    }

    private function supervisorOf(string $queue, HorizonReading $reading): ?string
    {
        foreach ($reading->masters as $master) {
            foreach ($master->supervisors as $supervisor) {
                foreach ($supervisor->processes as $key => $count) {
                    if ($count > 0 && $this->queueName($key) === $queue) {
                        return $supervisor->name;
                    }
                }
            }
        }

        return null;
    }

    private function queueName(string $key): string
    {
        return Str::after($key, ':');
    }

    /**
     * @return array{job: string, queue: string, exception: string, tries: int, failedAt: string}
     */
    private function failedJob(HorizonFailedJob $job): array
    {
        return [
            'job' => $job->name,
            'queue' => $job->queue,
            'exception' => $job->exception,
            'tries' => $job->attempts,
            'failedAt' => $job->failedAt->toIso8601String(),
        ];
    }

    /**
     * @param  list<HorizonPendingJob>  $jobs
     * @return list<array{job: string, queue: string, reservedAt: string}>
     */
    private function reservedJobs(array $jobs): array
    {
        $reserved = [];

        foreach ($jobs as $job) {
            if ($job->status === 'reserved' && $job->reservedAt !== null) {
                $reserved[] = [
                    'job' => $job->name,
                    'queue' => $job->queue,
                    'reservedAt' => $job->reservedAt->toIso8601String(),
                ];
            }
        }

        return $reserved;
    }
}
