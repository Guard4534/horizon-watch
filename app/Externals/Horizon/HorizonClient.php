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
use App\Externals\Http\ResolvedTarget;
use App\Externals\Http\SafeUrlGuard;
use App\Externals\Http\TransferWatch;
use App\Externals\Http\UrlRefused;
use Carbon\CarbonImmutable;
use Closure;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Exception\MalformedUriException;
use Illuminate\Container\Attributes\Config;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Psr\Http\Message\RequestInterface;
use Throwable;

final readonly class HorizonClient implements HorizonReader
{
    private const array MASTER_STATUSES = ['running', 'paused', 'inactive'];

    private const int MAX_NUMBER = 2_147_483_647;

    private const int MAX_NAME_LENGTH = 255;

    private const int MAX_TIMESTAMP = 253_402_300_799;

    public function __construct(
        private SafeUrlGuard $guard,
        #[Config('horizon-watch.horizon.max_body_bytes')]
        private int $maxBodyBytes,
        #[Config('horizon-watch.horizon.max_masters')]
        private int $maxMasters,
        #[Config('horizon-watch.horizon.max_supervisors')]
        private int $maxSupervisors,
        #[Config('horizon-watch.horizon.max_jobs')]
        private int $maxJobs,
        #[Config('horizon-watch.horizon.max_metric_queues')]
        private int $maxMetricQueues,
        #[Config('horizon-watch.horizon.metrics_concurrency')]
        private int $metricsConcurrency,
        #[Config('horizon-watch.horizon.exception_length')]
        private int $exceptionLength,
        #[Config('horizon-watch.http_timeout_seconds', 5)]
        private int $timeoutSeconds = 5,
        #[Config('horizon-watch.read_budget_seconds', 20)]
        private int|float $readBudgetSeconds = 20,
    ) {}

    public function read(HorizonTarget $target): HorizonReading
    {
        $deadline = hrtime(true) + (int) ($this->readBudgetSeconds * 1_000_000_000);

        $resolved = $this->resolve($target);

        $paths = ['stats' => 'stats', 'masters' => 'masters', 'workload' => 'workload', 'failed' => 'jobs/failed', 'pending' => 'jobs/pending'];

        $started = hrtime(true);
        $answers = $this->pool($target, $resolved, $paths, count($paths));
        $elapsed = hrtime(true) - $started;

        $stats = $this->stats($answers['stats']);
        $masters = $this->masters($answers['masters']);
        $workload = $this->workload($answers['workload']);

        return new HorizonReading(
            stats: $stats,
            masters: $masters,
            workload: $workload,
            failedJobs: $this->secondary(fn () => $this->failedJobs($answers['failed'])),
            pendingJobs: $this->secondary(fn () => $this->pendingJobs($answers['pending'])),
            queueRuntimes: $this->queueRuntimes($target, $resolved, $workload, $deadline),
            latencyMs: $this->latency($answers['stats'], $elapsed),
        );
    }

    public function probe(HorizonTarget $target): HorizonProbe
    {
        $resolved = $this->resolve($target);

        $started = hrtime(true);
        $answers = $this->pool($target, $resolved, ['stats' => 'stats', 'masters' => 'masters'], 2);
        $elapsed = hrtime(true) - $started;

        $stats = $this->stats($answers['stats']);

        return new HorizonProbe(
            status: $stats->status,
            masterCount: count($this->masters($answers['masters'])),
            latencyMs: $this->latency($answers['stats'], $elapsed),
        );
    }

    /**
     * @throws HorizonReadFailed
     */
    private function resolve(HorizonTarget $target): ResolvedTarget
    {
        try {
            return $this->guard->check($target->apiUrl('stats'));
        } catch (UrlRefused $exception) {
            throw new HorizonReadFailed($exception->reason);
        }
    }

    /**
     * @param  array<string, string>  $paths
     * @param  positive-int  $concurrency
     * @return array<string, array{response: mixed, watch: TransferWatch}>
     *
     * @throws HorizonReadFailed
     */
    private function pool(HorizonTarget $target, ResolvedTarget $resolved, array $paths, int $concurrency, ?int $deadline = null): array
    {
        $watches = array_map(fn () => new TransferWatch($this->maxBodyBytes), $paths);

        try {
            $responses = Http::pool(function (Pool $pool) use ($target, $resolved, $paths, $watches, $deadline) {
                foreach ($paths as $key => $path) {
                    $this->get($pool->as($key), $target, $resolved, $path, $watches[$key], $deadline);
                }
            }, $concurrency);
        } catch (MalformedUriException) {
            throw new HorizonReadFailed(ReadingError::Blocked);
        }

        $answers = [];

        foreach ($paths as $key => $path) {
            $answers[$key] = ['response' => $responses[$key] ?? null, 'watch' => $watches[$key]];
        }

        return $answers;
    }

    private function get(PendingRequest $request, HorizonTarget $target, ResolvedTarget $resolved, string $path, TransferWatch $watch, ?int $deadline): void
    {
        $request
            ->connectTimeout($this->timeoutSeconds)
            ->timeout($this->timeoutSeconds)
            ->withoutRedirecting()
            ->acceptJson()
            ->withOptions([
                'curl' => [CURLOPT_RESOLVE => [$resolved->curlResolve()]],
                'proxy' => ['no' => ['*']],
                ...$watch->options(),
            ]);

        if ($deadline !== null) {
            $request->withMiddleware($this->within($deadline));
        }

        if ($target->hasBasicAuth()) {
            $request->withBasicAuth((string) $target->username, (string) $target->password());
        }

        $request->get($target->apiUrl($path));
    }

    /**
     * @param  array{response: mixed, watch: TransferWatch}  $answer
     * @return array<array-key, mixed>
     *
     * @throws HorizonReadFailed
     */
    private function body(array $answer): array
    {
        ['response' => $response, 'watch' => $watch] = $answer;

        if ($watch->tooLarge) {
            throw new HorizonReadFailed(ReadingError::NotHorizon);
        }

        if (! $response instanceof Response || $watch->failed) {
            throw new HorizonReadFailed(ReadingError::Unreachable);
        }

        $status = $response->status();

        if ($status >= 500 || in_array($status, [408, 429], true)) {
            throw new HorizonReadFailed(ReadingError::Unreachable);
        }

        if (in_array($status, [401, 403], true)) {
            throw new HorizonReadFailed(ReadingError::Unauthorized);
        }

        $size = $response->toPsrResponse()->getBody()->getSize();

        if ($status !== 200 || $size === null || $size > $this->maxBodyBytes) {
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
     * @param  Closure(): T  $read
     * @return T|null
     */
    private function secondary(Closure $read): mixed
    {
        try {
            return $read();
        } catch (HorizonReadFailed) {
            return null;
        }
    }

    /**
     * @param  array{response: mixed, watch: TransferWatch}  $answer
     */
    private function stats(array $answer): HorizonStats
    {
        $body = $this->body($answer);

        if (array_is_list($body) || ! in_array($body['status'] ?? null, self::MASTER_STATUSES, true)) {
            throw new HorizonReadFailed(ReadingError::NotHorizon);
        }

        $wait = [];

        foreach (is_array($body['wait'] ?? null) ? $body['wait'] : [] as $queue => $seconds) {
            $seconds = $this->number($seconds);

            if (is_string($queue) && $seconds !== null) {
                $wait[$queue] = $seconds;
            }
        }

        return new HorizonStats(
            status: $body['status'],
            jobsPerMinute: $this->required($body['jobsPerMinute'] ?? null),
            failedJobs: $this->required($body['failedJobs'] ?? null),
            processes: $this->required($body['processes'] ?? null),
            pausedMasters: $this->number($body['pausedMasters'] ?? null) ?? 0,
            wait: $wait,
            failedJobsPeriodMinutes: $this->failedJobsPeriod($body['periods'] ?? null),
        );
    }

    private function failedJobsPeriod(mixed $periods): int
    {
        $minutes = is_array($periods) ? $this->finite($periods['failedJobs'] ?? null) : null;

        if ($minutes === null || $minutes < 1 || $minutes > self::MAX_NUMBER) {
            return HorizonStats::DEFAULT_FAILED_WINDOW_MINUTES;
        }

        return (int) round($minutes);
    }

    /**
     * @param  array{response: mixed, watch: TransferWatch}  $answer
     * @return list<HorizonMaster>
     */
    private function masters(array $answer): array
    {
        $masters = [];

        foreach ($this->body($answer) as $master) {
            if (! is_array($master)
                || ! is_string($master['name'] ?? null)
                || ! is_string($master['status'] ?? null)
                || ! is_array($master['supervisors'] ?? null)
                || ! array_is_list($master['supervisors'])) {
                throw new HorizonReadFailed(ReadingError::NotHorizon);
            }

            if (count($masters) < $this->maxMasters) {
                $masters[] = new HorizonMaster(
                    name: $this->name($master['name']),
                    status: $this->name($master['status']),
                    supervisors: array_map(
                        $this->supervisor(...),
                        array_slice($master['supervisors'], 0, $this->maxSupervisors),
                    ),
                );
            }
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
            $count = $this->number($count);

            if (is_string($queue) && $count !== null) {
                $processes[$queue] = $count;
            }
        }

        return new HorizonSupervisor(
            name: $this->name($supervisor['name']),
            status: $this->name($supervisor['status']),
            processes: $processes,
        );
    }

    /**
     * @param  array{response: mixed, watch: TransferWatch}  $answer
     * @return list<HorizonQueueLoad>
     */
    private function workload(array $answer): array
    {
        $body = $this->body($answer);

        if (! array_is_list($body)) {
            throw new HorizonReadFailed(ReadingError::NotHorizon);
        }

        return array_map(function (mixed $queue): HorizonQueueLoad {
            if (! is_array($queue) || ! is_string($queue['name'] ?? null)) {
                throw new HorizonReadFailed(ReadingError::NotHorizon);
            }

            return new HorizonQueueLoad(
                name: $this->name($queue['name']),
                length: $this->required($queue['length'] ?? null),
                wait: $this->required($queue['wait'] ?? null),
                processes: $this->required($queue['processes'] ?? null),
            );
        }, $body);
    }

    /**
     * @param  array{response: mixed, watch: TransferWatch}  $answer
     * @return list<array{name: string, queue: string, job: array<array-key, mixed>}>
     */
    private function jobs(array $answer): array
    {
        $jobs = $this->body($answer)['jobs'] ?? null;

        if (! is_array($jobs) || ! array_is_list($jobs)) {
            throw new HorizonReadFailed(ReadingError::NotHorizon);
        }

        $kept = [];

        foreach ($jobs as $job) {
            if (count($kept) < $this->maxJobs && is_array($job) && is_string($job['name'] ?? null) && is_string($job['queue'] ?? null)) {
                $kept[] = ['name' => $this->name($job['name']), 'queue' => $this->name($job['queue']), 'job' => $job];
            }
        }

        return $kept;
    }

    /**
     * @param  array{response: mixed, watch: TransferWatch}  $answer
     * @return list<HorizonFailedJob>
     */
    private function failedJobs(array $answer): array
    {
        $jobs = [];

        foreach ($this->jobs($answer) as ['name' => $name, 'queue' => $queue, 'job' => $job]) {
            $failedAt = $this->timestamp($job['failed_at'] ?? null);

            if ($failedAt === null) {
                continue;
            }

            $attempts = is_array($job['payload'] ?? null) ? $this->number($job['payload']['attempts'] ?? null) : null;

            $jobs[] = new HorizonFailedJob(
                name: $name,
                queue: $queue,
                exception: Str::limit(trim(Str::before(is_string($job['exception'] ?? null) ? $job['exception'] : '', "\n")), $this->exceptionLength, ''),
                attempts: $attempts ?? 0,
                failedAt: $failedAt,
            );
        }

        return $jobs;
    }

    /**
     * @param  array{response: mixed, watch: TransferWatch}  $answer
     * @return list<HorizonPendingJob>
     */
    private function pendingJobs(array $answer): array
    {
        $jobs = [];

        foreach ($this->jobs($answer) as ['name' => $name, 'queue' => $queue, 'job' => $job]) {
            $reservedAt = $this->timestamp($job['reserved_at'] ?? null);

            if ($reservedAt === null && ($job['reserved_at'] ?? null) !== null) {
                continue;
            }

            $jobs[] = new HorizonPendingJob(
                name: $name,
                queue: $queue,
                status: is_string($job['status'] ?? null) ? $this->name($job['status']) : 'pending',
                reservedAt: $reservedAt,
            );
        }

        return $jobs;
    }

    /**
     * @param  list<HorizonQueueLoad>  $workload
     * @return array<string, float>
     */
    private function queueRuntimes(HorizonTarget $target, ResolvedTarget $resolved, array $workload, int $deadline): array
    {
        usort($workload, fn (HorizonQueueLoad $a, HorizonQueueLoad $b) => $b->length <=> $a->length);

        $queues = array_slice(array_values(array_unique(array_map(
            fn (HorizonQueueLoad $queue): string => $queue->name,
            $workload,
        ))), 0, $this->maxMetricQueues);

        if ($queues === [] || $this->millisecondsLeft($deadline) < 1) {
            return [];
        }

        $paths = [];

        foreach ($queues as $index => $queue) {
            $paths['q'.$index] = 'metrics/queues/'.rawurlencode($queue);
        }

        $answers = $this->pool($target, $resolved, $paths, max(1, $this->metricsConcurrency), $deadline);
        $runtimes = [];

        foreach ($queues as $index => $queue) {
            $snapshots = $this->secondary(fn () => $this->body($answers['q'.$index]));

            if ($snapshots === null || $snapshots === [] || ! array_is_list($snapshots)) {
                continue;
            }

            $last = end($snapshots);
            $runtime = is_array($last) ? $this->finite($last['runtime'] ?? null) : null;

            if ($runtime !== null) {
                $runtimes[$queue] = min(max($runtime, 0.0), (float) self::MAX_NUMBER);
            }
        }

        return $runtimes;
    }

    /**
     * @return Closure(callable): (Closure(RequestInterface, array<string, mixed>): PromiseInterface)
     */
    private function within(int $deadline): Closure
    {
        return fn (callable $handler): Closure => function (RequestInterface $request, array $options) use ($handler, $deadline): PromiseInterface {
            $left = $this->millisecondsLeft($deadline);

            if ($left < 1) {
                return Create::rejectionFor(new ConnectException('The read budget is spent.', $request));
            }

            $seconds = min($this->timeoutSeconds * 1000, $left) / 1000;

            return $handler($request, ['connect_timeout' => $seconds, 'timeout' => $seconds] + $options);
        };
    }

    private function millisecondsLeft(int $deadline): int
    {
        return intdiv($deadline - hrtime(true), 1_000_000);
    }

    /**
     * @throws HorizonReadFailed
     */
    private function required(mixed $value): int
    {
        return $this->number($value) ?? throw new HorizonReadFailed(ReadingError::NotHorizon);
    }

    private function number(mixed $value): ?int
    {
        $number = $this->finite($value);

        return $number === null ? null : (int) round(min(max($number, 0.0), (float) self::MAX_NUMBER));
    }

    private function finite(mixed $value): ?float
    {
        if (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value))) {
            return null;
        }

        $number = (float) $value;

        return is_finite($number) ? $number : null;
    }

    private function timestamp(mixed $value): ?CarbonImmutable
    {
        $seconds = $this->finite($value);

        if ($seconds === null || $seconds < 0 || $seconds > self::MAX_TIMESTAMP) {
            return null;
        }

        try {
            return CarbonImmutable::createFromTimestamp($seconds, 'UTC');
        } catch (Throwable) {
            return null;
        }
    }

    private function name(string $value): string
    {
        return mb_substr($value, 0, self::MAX_NAME_LENGTH);
    }

    /**
     * @param  array{response: mixed, watch: TransferWatch}  $answer
     */
    private function latency(array $answer, int|float $elapsedNanoseconds): int
    {
        $response = $answer['response'];
        $seconds = $response instanceof Response ? $response->transferStats?->getTransferTime() : null;

        return (int) round(min(
            $seconds !== null ? $seconds * 1000 : $elapsedNanoseconds / 1_000_000,
            (float) self::MAX_NUMBER,
        ));
    }
}
