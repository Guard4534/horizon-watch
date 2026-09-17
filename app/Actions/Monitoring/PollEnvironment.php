<?php

namespace App\Actions\Monitoring;

use App\Enums\ReadingError;
use App\Externals\Horizon\Data\HorizonFailedJob;
use App\Externals\Horizon\Data\HorizonMaster;
use App\Externals\Horizon\Data\HorizonPendingJob;
use App\Externals\Horizon\Data\HorizonQueueLoad;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class PollEnvironment
{
    public function __construct(
        private readonly HorizonReader $reader,
        private readonly StatusEvaluator $evaluator,
    ) {}

    /**
     * Read the environment once and store the result: a new snapshot row
     * and the replaced state row, in one transaction.
     */
    public function handle(Environment $environment): EnvironmentSnapshot
    {
        $capturedAt = CarbonImmutable::now();

        try {
            $reading = $this->reader->read(HorizonTarget::fromEnvironment($environment));
        } catch (HorizonReadFailed $exception) {
            return $this->storeFailure($environment, $capturedAt, $exception->reason);
        } catch (Throwable $exception) {
            // The reader promises HorizonReadFailed only. Anything else is a
            // bug in it, and its message may carry what an HTTP library put
            // there (a URL with credentials, a response body): it is
            // reported by class name alone, never chained, and the reading
            // counts as unreachable.
            report(new RuntimeException('The Horizon reader threw '.$exception::class.' instead of HorizonReadFailed.'));

            return $this->storeFailure($environment, $capturedAt, ReadingError::Unreachable);
        }

        return $this->storeReading($environment, $capturedAt, $reading);
    }

    private function storeReading(Environment $environment, CarbonImmutable $capturedAt, HorizonReading $reading): EnvironmentSnapshot
    {
        $evaluated = $this->evaluator->evaluate($reading);

        $state = [
            'status' => $evaluated->status,
            'error' => null,
            'latency_ms' => $reading->latencyMs,
            'nodes' => array_map($this->node(...), $reading->masters),
            'queues' => array_map(fn (HorizonQueueLoad $queue) => $this->queue($queue, $reading), $reading->workload),
        ];

        // A secondary call that failed keeps the section of the previous
        // reading: it is left out of the update and only inserted, empty,
        // when there is no previous reading at all.
        $sectionsOnInsert = [];

        if ($reading->failedJobs === null) {
            $sectionsOnInsert['failed_jobs'] = [];
        } else {
            $state['failed_jobs'] = array_map($this->failedJob(...), $reading->failedJobs);
        }

        if ($reading->pendingJobs === null) {
            $sectionsOnInsert['pending_jobs'] = [];
        } else {
            $state['pending_jobs'] = $this->reservedJobs($reading->pendingJobs);
        }

        return $this->store($environment, $capturedAt, $evaluated, [
            'pending' => array_sum(array_map(fn (HorizonQueueLoad $queue) => $queue->length, $reading->workload)),
            'max_wait_seconds' => max([0, ...array_map(fn (HorizonQueueLoad $queue) => $queue->wait, $reading->workload)]),
            'jobs_per_minute' => $reading->stats->jobsPerMinute,
            'failed_last_24_hours' => $reading->stats->failedJobs,
            'workers' => $reading->stats->processes,
            'node_count' => count($reading->masters),
            'latency_ms' => $reading->latencyMs,
        ], $state, $sectionsOnInsert);
    }

    private function storeFailure(Environment $environment, CarbonImmutable $capturedAt, ReadingError $error): EnvironmentSnapshot
    {
        $evaluated = $this->evaluator->failed($error);

        // Nothing was measured; the state keeps the detail of the last
        // reading that worked, so the page still shows the last known
        // nodes, queues and jobs next to the error.
        return $this->store($environment, $capturedAt, $evaluated, [
            'error' => $error,
            'pending' => 0,
            'max_wait_seconds' => 0,
            'jobs_per_minute' => 0,
            'failed_last_24_hours' => 0,
            'workers' => 0,
            'node_count' => 0,
            'latency_ms' => null,
        ], [
            'status' => $evaluated->status,
            'error' => $error,
            'latency_ms' => null,
        ], [
            'nodes' => [],
            'queues' => [],
            'failed_jobs' => [],
            'pending_jobs' => [],
        ]);
    }

    /**
     * @param  array<string, mixed>  $snapshot  counters, on top of the evaluated status
     * @param  array<string, mixed>  $state  columns written on insert and on update
     * @param  array<string, mixed>  $insertOnly  columns written only when the environment has no state yet
     */
    private function store(
        Environment $environment,
        CarbonImmutable $capturedAt,
        EvaluatedStatus $evaluated,
        array $snapshot,
        array $state,
        array $insertOnly,
    ): EnvironmentSnapshot {
        return DB::transaction(function () use ($environment, $capturedAt, $evaluated, $snapshot, $state, $insertOnly) {
            $stored = EnvironmentSnapshot::query()->create([
                'environment_id' => $environment->id,
                'captured_at' => $capturedAt,
                'status' => $evaluated->status,
                'error' => null,
                'breaches' => $evaluated->breaches,
                ...$snapshot,
            ]);

            $state = ['captured_at' => $capturedAt, ...$state];

            // An upsert rather than updateOrCreate: two readings of the same
            // environment racing past the unique job lock would otherwise
            // both try the insert and one would fail on the unique index.
            // The query builder does not cast, so a model instance does.
            $row = (new EnvironmentState)
                ->forceFill(['environment_id' => $environment->id, ...$insertOnly, ...$state])
                ->getAttributes();

            EnvironmentState::query()->upsert($row, ['environment_id'], array_keys($state));

            // Through the base query: an Eloquent update would also bump
            // updated_at, which records configuration changes, every poll.
            Environment::query()->whereKey($environment->id)->toBase()->update(['last_polled_at' => $capturedAt]);

            $environment->forceFill(['last_polled_at' => $capturedAt])->syncOriginalAttribute('last_polled_at');

            return $stored;
        });
    }

    /**
     * @return array{hostname: string, status: string, workers: int, supervisors: int, queues: int}
     */
    private function node(HorizonMaster $master): array
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
            // Horizon writes only "running" or "paused" for a master.
            'status' => $master->status === 'paused' ? 'paused' : 'running',
            'workers' => array_sum($processes),
            'supervisors' => count($master->supervisors),
            'queues' => count($queues),
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

    /**
     * The first supervisor, in the order Horizon lists them, that runs at
     * least one process for the queue.
     */
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

    /**
     * Horizon keys processes by "connection:queue".
     */
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
     * Only a reserved job can be running for too long; a job still waiting
     * is the queue's wait, which the workload already measures.
     *
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
