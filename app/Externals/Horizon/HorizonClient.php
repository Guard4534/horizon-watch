<?php

namespace App\Externals\Horizon;

use App\Enums\ReadingError;
use App\Externals\Horizon\Data\HorizonFailedJob;
use App\Externals\Horizon\Data\HorizonMaster;
use App\Externals\Horizon\Data\HorizonPendingJob;
use App\Externals\Horizon\Data\HorizonQueueLoad;
use App\Externals\Horizon\Data\HorizonStats;
use App\Externals\Horizon\Data\HorizonSupervisor;
use App\Externals\Horizon\Exceptions\HorizonReadFailed;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\Config;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Nothing is logged here, and no failure carries more than a ReadingError:
 * HTTP client exceptions hold the URL and sometimes the body, so they are
 * dropped rather than chained.
 */
final readonly class HorizonClient implements HorizonReader
{
    private const array MASTER_STATUSES = ['running', 'paused', 'inactive'];

    public function __construct(
        private SafeUrlGuard $guard,
        #[Config('horizon-watch.http_timeout_seconds', 5)]
        private int $timeoutSeconds = 5,
    ) {}

    public function read(HorizonTarget $target): HorizonReading
    {
        $resolved = $this->guard->check($target->apiUrl('stats'));

        $started = hrtime(true);
        $responses = Http::pool(fn (Pool $pool) => [
            $this->get($pool->as('stats'), $target, $resolved, 'stats'),
            $this->get($pool->as('masters'), $target, $resolved, 'masters'),
            $this->get($pool->as('workload'), $target, $resolved, 'workload'),
            $this->get($pool->as('failed'), $target, $resolved, 'jobs/failed'),
            $this->get($pool->as('pending'), $target, $resolved, 'jobs/pending'),
        ]);
        $elapsed = hrtime(true) - $started;

        $stats = $this->stats($responses['stats']);
        $masters = $this->masters($responses['masters']);
        $workload = $this->workload($responses['workload']);

        return new HorizonReading(
            stats: $stats,
            masters: $masters,
            workload: $workload,
            failedJobs: $this->secondary(fn () => $this->failedJobs($responses['failed'])),
            pendingJobs: $this->secondary(fn () => $this->pendingJobs($responses['pending'])),
            queueRuntimes: $this->queueRuntimes($target, $resolved, $workload),
            latencyMs: $this->latency($responses['stats'], $elapsed),
        );
    }

    public function probe(HorizonTarget $target): HorizonProbe
    {
        $resolved = $this->guard->check($target->apiUrl('stats'));

        $started = hrtime(true);
        $responses = Http::pool(fn (Pool $pool) => [
            $this->get($pool->as('stats'), $target, $resolved, 'stats'),
            $this->get($pool->as('masters'), $target, $resolved, 'masters'),
        ]);
        $elapsed = hrtime(true) - $started;

        $stats = $this->stats($responses['stats']);

        return new HorizonProbe(
            status: $stats->status,
            masterCount: count($this->masters($responses['masters'])),
            latencyMs: $this->latency($responses['stats'], $elapsed),
        );
    }

    /**
     * CURLOPT_RESOLVE makes cURL connect to the address the guard checked,
     * so a DNS answer that changes between the check and the request cannot
     * redirect it. A proxy would resolve the name itself and undo that, so
     * proxies from the environment are bypassed.
     */
    private function get(PendingRequest $request, HorizonTarget $target, ResolvedTarget $resolved, string $path): mixed
    {
        $request
            ->connectTimeout($this->timeoutSeconds)
            ->timeout($this->timeoutSeconds)
            ->withoutRedirecting()
            ->acceptJson()
            ->withOptions([
                'curl' => [CURLOPT_RESOLVE => [$resolved->curlResolve()]],
                'proxy' => ['no' => ['*']],
            ]);

        if ($target->hasBasicAuth()) {
            $request->withBasicAuth((string) $target->username, (string) $target->password());
        }

        return $request->get($target->apiUrl($path));
    }

    /**
     * A pool answers with the Response or with the exception the request
     * ended in; either way only the ReadingError survives.
     *
     * @return array<array-key, mixed>
     *
     * @throws HorizonReadFailed
     */
    private function body(mixed $response): array
    {
        if (! $response instanceof Response) {
            throw new HorizonReadFailed(ReadingError::Unreachable);
        }

        $status = $response->status();

        if ($status >= 500 || in_array($status, [408, 429], true)) {
            throw new HorizonReadFailed(ReadingError::Unreachable);
        }

        if (in_array($status, [401, 403], true)) {
            throw new HorizonReadFailed(ReadingError::Unauthorized);
        }

        if ($status !== 200) {
            throw new HorizonReadFailed(ReadingError::NotHorizon);
        }

        $decoded = json_decode($response->body(), true);

        if (! is_array($decoded)) {
            throw new HorizonReadFailed(ReadingError::NotHorizon);
        }

        return $decoded;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $read
     * @return T|null
     */
    private function secondary(callable $read): mixed
    {
        try {
            return $read();
        } catch (HorizonReadFailed) {
            return null;
        }
    }

    private function stats(mixed $response): HorizonStats
    {
        $body = $this->body($response);

        if (array_is_list($body)
            || ! in_array($body['status'] ?? null, self::MASTER_STATUSES, true)
            || ! is_numeric($body['jobsPerMinute'] ?? null)
            || ! is_numeric($body['failedJobs'] ?? null)
            || ! is_numeric($body['processes'] ?? null)) {
            throw new HorizonReadFailed(ReadingError::NotHorizon);
        }

        $wait = [];

        foreach (is_array($body['wait'] ?? null) ? $body['wait'] : [] as $queue => $seconds) {
            if (is_string($queue) && is_numeric($seconds)) {
                $wait[$queue] = (int) round((float) $seconds);
            }
        }

        return new HorizonStats(
            status: $body['status'],
            jobsPerMinute: (int) round((float) $body['jobsPerMinute']),
            failedJobs: (int) $body['failedJobs'],
            processes: (int) $body['processes'],
            pausedMasters: is_numeric($body['pausedMasters'] ?? null) ? (int) $body['pausedMasters'] : 0,
            wait: $wait,
        );
    }

    /**
     * Horizon keys masters by name, and answers an empty list, not an empty
     * object, when there are none.
     *
     * @return list<HorizonMaster>
     */
    private function masters(mixed $response): array
    {
        $masters = [];

        foreach ($this->body($response) as $master) {
            if (! is_array($master)
                || ! is_string($master['name'] ?? null)
                || ! is_string($master['status'] ?? null)
                || ! is_array($master['supervisors'] ?? null)
                || ! array_is_list($master['supervisors'])) {
                throw new HorizonReadFailed(ReadingError::NotHorizon);
            }

            $masters[] = new HorizonMaster(
                name: $master['name'],
                status: $master['status'],
                supervisors: array_map($this->supervisor(...), $master['supervisors']),
            );
        }

        return $masters;
    }

    private function supervisor(mixed $supervisor): HorizonSupervisor
    {
        if (! is_array($supervisor) || ! is_string($supervisor['name'] ?? null) || ! is_string($supervisor['status'] ?? null)) {
            throw new HorizonReadFailed(ReadingError::NotHorizon);
        }

        $processes = [];

        foreach (is_array($supervisor['processes'] ?? null) ? $supervisor['processes'] : [] as $queue => $count) {
            if (is_string($queue) && is_numeric($count)) {
                $processes[$queue] = (int) $count;
            }
        }

        return new HorizonSupervisor(name: $supervisor['name'], status: $supervisor['status'], processes: $processes);
    }

    /**
     * @return list<HorizonQueueLoad>
     */
    private function workload(mixed $response): array
    {
        $body = $this->body($response);

        if (! array_is_list($body)) {
            throw new HorizonReadFailed(ReadingError::NotHorizon);
        }

        return array_map(function (mixed $queue): HorizonQueueLoad {
            if (! is_array($queue)
                || ! is_string($queue['name'] ?? null)
                || ! is_numeric($queue['length'] ?? null)
                || ! is_numeric($queue['wait'] ?? null)
                || ! is_numeric($queue['processes'] ?? null)) {
                throw new HorizonReadFailed(ReadingError::NotHorizon);
            }

            return new HorizonQueueLoad(
                name: $queue['name'],
                length: (int) $queue['length'],
                wait: (int) round((float) $queue['wait']),
                processes: (int) $queue['processes'],
            );
        }, $body);
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function jobs(mixed $response): array
    {
        $jobs = $this->body($response)['jobs'] ?? null;

        if (! is_array($jobs) || ! array_is_list($jobs)) {
            throw new HorizonReadFailed(ReadingError::NotHorizon);
        }

        return array_values(array_filter(
            $jobs,
            fn (mixed $job): bool => is_array($job) && is_string($job['name'] ?? null) && is_string($job['queue'] ?? null),
        ));
    }

    /**
     * Only the first line of the exception is kept, and nothing of the
     * payload but the attempt count: both may hold whatever the application
     * put in them.
     *
     * @return list<HorizonFailedJob>
     */
    private function failedJobs(mixed $response): array
    {
        $jobs = [];

        foreach ($this->jobs($response) as $job) {
            if (! is_numeric($job['failed_at'] ?? null)) {
                continue;
            }

            $attempts = is_array($job['payload'] ?? null) && is_numeric($job['payload']['attempts'] ?? null)
                ? (int) $job['payload']['attempts']
                : 0;

            $jobs[] = new HorizonFailedJob(
                name: $job['name'],
                queue: $job['queue'],
                exception: Str::limit(trim(Str::before(is_string($job['exception'] ?? null) ? $job['exception'] : '', "\n")), 200, ''),
                attempts: $attempts,
                failedAt: $this->timestamp($job['failed_at']),
            );
        }

        return $jobs;
    }

    /**
     * @return list<HorizonPendingJob>
     */
    private function pendingJobs(mixed $response): array
    {
        return array_map(fn (array $job): HorizonPendingJob => new HorizonPendingJob(
            name: $job['name'],
            queue: $job['queue'],
            status: is_string($job['status'] ?? null) ? $job['status'] : 'pending',
            reservedAt: is_numeric($job['reserved_at'] ?? null) ? $this->timestamp($job['reserved_at']) : null,
        ), $this->jobs($response));
    }

    /**
     * Horizon already divides the snapshot runtime by 1000, so the value is
     * in seconds. The last snapshot is the most recent one.
     *
     * @param  list<HorizonQueueLoad>  $workload
     * @return array<string, float>
     */
    private function queueRuntimes(HorizonTarget $target, ResolvedTarget $resolved, array $workload): array
    {
        $queues = array_values(array_unique(array_map(fn (HorizonQueueLoad $queue): string => $queue->name, $workload)));

        if ($queues === []) {
            return [];
        }

        $responses = Http::pool(fn (Pool $pool) => array_map(
            fn (int $index, string $queue) => $this->get($pool->as((string) $index), $target, $resolved, 'metrics/queues/'.rawurlencode($queue)),
            array_keys($queues),
            $queues,
        ));

        $runtimes = [];

        foreach ($queues as $index => $queue) {
            $snapshots = $this->secondary(fn () => $this->body($responses[(string) $index] ?? null));

            if ($snapshots === null || $snapshots === [] || ! array_is_list($snapshots)) {
                continue;
            }

            $last = end($snapshots);

            if (is_array($last) && is_numeric($last['runtime'] ?? null)) {
                $runtimes[$queue] = (float) $last['runtime'];
            }
        }

        return $runtimes;
    }

    private function timestamp(int|float|string $value): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestamp((float) $value, 'UTC');
    }

    /**
     * The transfer time of the stats request when cURL reports it; the
     * duration of the whole pool otherwise, which is never shorter.
     */
    private function latency(mixed $response, int|float $elapsedNanoseconds): int
    {
        $seconds = $response instanceof Response ? $response->transferStats?->getTransferTime() : null;

        return (int) round($seconds !== null ? $seconds * 1000 : $elapsedNanoseconds / 1_000_000);
    }
}
