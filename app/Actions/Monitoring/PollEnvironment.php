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
    // Horizon's failed-jobs window when the application sets none.
    private const HORIZON_FAILED_WINDOW_MINUTES = 10080;

    public function __construct(
        private readonly HorizonReader $reader,
        private readonly StatusEvaluator $evaluator,
    ) {}

    /**
     * Read the environment once and store the result: a new snapshot row
     * and the replaced state row, in one transaction. Returns null when the
     * environment was deleted while it was being read.
     */
    public function handle(Environment $environment): ?EnvironmentSnapshot
    {
        $capturedAt = CarbonImmutable::now();

        // Outside the try: a password that no longer decrypts (a rotated
        // APP_KEY) is a failure of this panel, not of Horizon, and must
        // surface as the DecryptException it is.
        $target = HorizonTarget::fromEnvironment($environment);

        try {
            $reading = $this->reader->read($target);
        } catch (HorizonReadFailed $exception) {
            return $this->storeFailure($environment, $capturedAt, $exception->reason);
        } catch (Throwable $exception) {
            // The reader promises HorizonReadFailed only. Anything else is a
            // bug in it, and its message may carry what an HTTP library put
            // there (a URL with credentials, a response body): it is
            // reported by class, file and line alone, never chained, and the
            // reading counts as unreachable.
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
        $evaluated = $this->evaluator->evaluate($reading);

        $state = [
            'status' => $evaluated->status,
            'error' => null,
            'latency_ms' => $reading->latencyMs,
            'nodes' => array_map(fn (HorizonMaster $master) => $this->node($master, $capturedAt), $reading->masters),
            'queues' => array_map(fn (HorizonQueueLoad $queue) => $this->queue($queue, $reading), $reading->workload),
            // Reserved jobs are a live measurement: the page shows how long
            // each has been running, measured from now. Kept from an older
            // reading they would keep "running" long after they finished,
            // so a failed call empties the section instead.
            'pending_jobs' => $reading->pendingJobs === null ? [] : $this->reservedJobs($reading->pendingJobs),
        ];

        // Failed jobs are context, not a measurement: a failed call keeps
        // the section of the previous reading. It is left out of the update
        // and only inserted, empty, when there is no previous reading.
        $sectionsOnInsert = [];

        if ($reading->failedJobs === null) {
            $sectionsOnInsert['failed_jobs'] = [];
        } else {
            $state['failed_jobs'] = array_map($this->failedJob(...), $reading->failedJobs);
        }

        return $this->store($environment, $capturedAt, $evaluated, [
            'pending' => array_sum(array_map(fn (HorizonQueueLoad $queue) => $queue->length, $reading->workload)),
            'max_wait_seconds' => max([0, ...array_map(fn (HorizonQueueLoad $queue) => $queue->wait, $reading->workload)]),
            'jobs_per_minute' => $reading->stats->jobsPerMinute,
            'failed_last_24_hours' => $reading->stats->failedJobs,
            'failed_window_minutes' => $reading->stats->failedJobsPeriodMinutes,
            'workers' => $reading->stats->processes,
            'node_count' => count($reading->masters),
            'latency_ms' => $reading->latencyMs,
        ], $state, $sectionsOnInsert);
    }

    private function storeFailure(Environment $environment, CarbonImmutable $capturedAt, ReadingError $error): ?EnvironmentSnapshot
    {
        $evaluated = $this->evaluator->failed($error);

        // Nothing was measured. Nodes, queues and failed jobs are context:
        // the state keeps those of the last reading that worked, so the page
        // still shows them next to the error, each node with the time it was
        // last listed. Reserved jobs are a live measurement (their running
        // time is counted from now), so they are emptied rather than kept.
        return $this->store($environment, $capturedAt, $evaluated, [
            'error' => $error,
            'pending' => 0,
            'max_wait_seconds' => 0,
            'jobs_per_minute' => 0,
            'failed_last_24_hours' => 0,
            'failed_window_minutes' => $this->previousFailedWindow($environment),
            'workers' => 0,
            'node_count' => 0,
            'latency_ms' => null,
        ], [
            'status' => $evaluated->status,
            'error' => $error,
            'latency_ms' => null,
            'pending_jobs' => [],
        ], [
            'nodes' => [],
            'queues' => [],
            'failed_jobs' => [],
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
    ): ?EnvironmentSnapshot {
        return DB::transaction(function () use ($environment, $capturedAt, $evaluated, $snapshot, $state, $insertOnly) {
            // The same lock the snapshot's foreign key would take, taken
            // first: a delete committed while Horizon was being read leaves
            // nothing to lock, and a harmless race ends quietly instead of
            // as a foreign-key failure; a delete arriving now waits for us.
            $locked = DB::table('environments')->where('id', $environment->id)->lock('for key share')->value('id');

            if ($locked === null) {
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

            $this->upsertState($environment, [
                'captured_at' => $capturedAt,
                ...$state,
            ], $insertOnly);

            // Through the base query: an Eloquent update would also bump
            // updated_at, which records configuration changes, every poll.
            // Never moved backwards by a reading that finished late.
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

    /**
     * A failed reading measured no failed jobs, so it has no window of its
     * own. It repeats the one of the reading stored before it (which a
     * failed reading also carried forward), or the page would label the
     * failed jobs of a week-long window "24h" for the length of an outage.
     * Without a reading before it, Horizon's own default applies: it trims
     * failed jobs after a week (horizon.trim.failed, 10080 minutes).
     *
     * One row, read backwards on the (environment_id, captured_at) index.
     * The row comparison is what gets PostgreSQL there: with an equality on
     * a constant id it walked the captured_at index instead (167 ms for an
     * environment whose last reading is ten days old, on 5 M rows). Without
     * the equality the first row found may belong to the environment before
     * this one in id order, hence the check on the returned id.
     */
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
            : self::HORIZON_FAILED_WINDOW_MINUTES;
    }

    /**
     * An upsert rather than updateOrCreate: two readings of the same
     * environment (two workers, or a duplicate job) would otherwise both try
     * the insert and one would fail on the unique index. The update only
     * applies when the reading is not older than the stored one, so a
     * reading that finished late never replaces a newer state. The query
     * builder has no WHERE for ON CONFLICT, hence the appended clause, and
     * does not cast, hence the model instance.
     *
     * @param  array<string, mixed>  $state  columns written on insert and on update
     * @param  array<string, mixed>  $insertOnly  columns written only on insert
     */
    private function upsertState(Environment $environment, array $state, array $insertOnly): void
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

        $sql = $grammar->compileUpsert($query, [$row], ['environment_id'], [...array_keys($state), 'updated_at'])
            .' where '.$grammar->wrap($model->getTable().'.captured_at').' <= '.$grammar->wrap('excluded.captured_at');

        DB::affectingStatement($sql, array_values($row));
    }

    /**
     * seenAt is the reading that listed the master: the state keeps a node
     * through failed readings, and "seen N s ago" must keep counting from
     * the last time Horizon actually listed it.
     *
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
            // Horizon writes only "running" or "paused" for a master.
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
