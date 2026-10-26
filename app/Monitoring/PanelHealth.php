<?php

namespace App\Monitoring;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Throwable;

#[Singleton]
class PanelHealth
{
    public const array SCHEDULED_TASKS = ['dispatch-due-polls', 'alert-repeats'];

    private const string SCHEDULER_KEY = 'horizon-watch.health.scheduler.';

    private const string WORKER_KEY = 'horizon-watch.health.worker.';

    private ?int $lastWorkerBeat = null;

    public function recordSchedulerTick(string $task): void
    {
        Cache::forever(self::SCHEDULER_KEY.$task, Date::now()->getTimestamp());
    }

    public function recordWorkerBeat(): void
    {
        $now = Date::now()->getTimestamp();

        if ($this->lastWorkerBeat !== null && $now - $this->lastWorkerBeat < config()->integer('horizon-watch.health.worker_beat_seconds')) {
            return;
        }

        Cache::put(self::WORKER_KEY.self::host(), $now, Date::now()->addDay());
        $this->lastWorkerBeat = $now;
    }

    public function report(bool $countFailedJobs = true): HealthReport
    {
        if (! $this->databaseIsUp()) {
            return new HealthReport(database: false, schedulerSecondsAgo: null, queueWaitSeconds: [], failedJobsLastHour: null);
        }

        return new HealthReport(
            database: true,
            schedulerSecondsAgo: $this->schedulerSecondsAgo(),
            queueWaitSeconds: $this->queueWaitSeconds(),
            failedJobsLastHour: $countFailedJobs ? $this->failedJobsLastHour() : null,
        );
    }

    public function databaseIsUp(): bool
    {
        try {
            DB::select('select 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function schedulerSecondsAgo(): ?int
    {
        $ages = array_map(fn (string $task) => $this->secondsSince(Cache::get(self::SCHEDULER_KEY.$task)), self::SCHEDULED_TASKS);

        return in_array(null, $ages, true) ? null : max($ages);
    }

    public function workerSecondsAgo(): ?int
    {
        return $this->secondsSince(Cache::get(self::WORKER_KEY.self::host()));
    }

    /**
     * @return array<string, int>
     */
    private function queueWaitSeconds(): array
    {
        $queue = config('queue.connections.'.config()->string('queue.default'));

        if (! is_array($queue) || ($queue['driver'] ?? null) !== 'database') {
            return [];
        }

        $now = Date::now()->getTimestamp();
        $retryAfter = is_int($queue['retry_after'] ?? null) ? $queue['retry_after'] : 90;

        return DB::connection(is_string($queue['connection'] ?? null) ? $queue['connection'] : null)
            ->table(is_string($queue['table'] ?? null) ? $queue['table'] : 'jobs')
            ->where('available_at', '<=', $now)
            ->where(fn ($query) => $query->whereNull('reserved_at')->orWhere('reserved_at', '<=', $now - $retryAfter))
            ->groupBy('queue')
            ->orderBy('queue')
            ->selectRaw('queue, min(available_at) as since')
            ->get()
            ->mapWithKeys(fn (object $row) => [(string) $row->queue => max(0, $now - (int) $row->since)])
            ->all();
    }

    private function failedJobsLastHour(): ?int
    {
        $failed = config('queue.failed');

        if (! is_array($failed) || ! in_array($failed['driver'] ?? null, ['database', 'database-uuids'], true)) {
            return null;
        }

        return DB::connection(is_string($failed['database'] ?? null) ? $failed['database'] : null)
            ->table(is_string($failed['table'] ?? null) ? $failed['table'] : 'failed_jobs')
            ->where('failed_at', '>=', Date::now()->subHour())
            ->count();
    }

    private function secondsSince(mixed $timestamp): ?int
    {
        return is_int($timestamp) ? max(0, Date::now()->getTimestamp() - $timestamp) : null;
    }

    private static function host(): string
    {
        return gethostname() ?: 'localhost';
    }
}
