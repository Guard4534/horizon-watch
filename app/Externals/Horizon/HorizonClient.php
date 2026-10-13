<?php

namespace App\Externals\Horizon;

use App\Enums\ReadingCause;
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
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Psr\Http\Message\RequestInterface;
use Throwable;

final readonly class HorizonClient implements HorizonReader
{
    private const array MASTER_STATUSES = ['running', 'paused', 'inactive'];

    private const int MAX_NUMBER = 2_147_483_647;

    private const int MAX_NAME_LENGTH = 255;

    private const int MAX_TIMESTAMP = 253_402_300_799;

    private const array MAIN_PATHS = ['stats', 'masters', 'workload'];

    private const array TRANSIENT_STATUSES = [502, 503, 504];

    private const int RETRY_DELAY_MS = 400;

    public function __construct(
        private SafeUrlGuard $guard,
        #[Config('horizon-watch.horizon.max_body_bytes')]
        private int $maxBodyBytes,
        #[Config('horizon-watch.horizon.max_masters')]
        private int $maxMasters,
        #[Config('horizon-watch.horizon.max_supervisors')]
        private int $maxSupervisors,
        #[Config('horizon-watch.horizon.max_queues')]
        private int $maxQueues,
        #[Config('horizon-watch.horizon.max_jobs')]
        private int $maxJobs,
        #[Config('horizon-watch.horizon.max_job_pages')]
        private int $maxJobPages,
        #[Config('horizon-watch.horizon.max_metric_queues')]
        private int $maxMetricQueues,
        #[Config('horizon-watch.horizon.metrics_concurrency')]
        private int $metricsConcurrency,
        #[Config('horizon-watch.horizon.exception_length')]
        private int $exceptionLength,
        #[Config('horizon-watch.readings.failed_rate_minutes')]
        private int $failedRateMinutes,
        #[Config('horizon-watch.http_timeout_seconds', 5)]
        private int $timeoutSeconds = 5,
        #[Config('horizon-watch.read_budget_seconds', 20)]
        private int|float $readBudgetSeconds = 20,
    ) {}

    /**
     * @param  array<string, float|null>|null  $knownRuntimes
     */
    public function read(HorizonTarget $target, bool $oldestPendingJobs = false, ?array $knownRuntimes = null): HorizonReading
    {
        $deadline = hrtime(true) + (int) ($this->readBudgetSeconds * 1_000_000_000);

        $resolved = $this->resolve($target);

        $paths = ['stats' => 'stats', 'masters' => 'masters', 'workload' => 'workload', 'failed' => 'jobs/failed', 'pending' => 'jobs/pending'];

        $started = hrtime(true);
        $answers = $this->pool($target, $resolved, $paths, count($paths));

        if ($this->worthRetrying($answers) && $this->millisecondsLeft($deadline) > $this->timeoutSeconds * 1000 + self::RETRY_DELAY_MS) {
            Sleep::for(self::RETRY_DELAY_MS)->milliseconds();

            $started = hrtime(true);
            $answers = $this->pool($target, $resolved, $paths, count($paths), $deadline);
        }

        $elapsed = hrtime(true) - $started;

        $stats = $this->stats($answers['stats']);
        $masters = $this->masters($answers['masters']);
        $workload = $this->workload($answers['workload']);

        $failedPage = $this->secondary(fn () => $this->page($answers['failed']));
        $pendingPage = $this->secondary(fn () => $this->page($answers['pending']));

        $failedOffsets = $failedPage !== null && $this->endsInWindow($failedPage) ? $this->pageOffsets($failedPage, fromTheEnd: false) : [];
        $pendingOffsets = $pendingPage !== null && $oldestPendingJobs ? $this->pageOffsets($pendingPage, fromTheEnd: true) : [];

        $queues = $this->busiestQueues($workload);
        $reuseRuntimes = $knownRuntimes !== null && array_diff($queues, array_map(strval(...), array_keys($knownRuntimes))) === [];

        $followUps = [];

        foreach ($failedOffsets as $offset) {
            $followUps['failed'.$offset] = 'jobs/failed?starting_at='.($offset - 1);
        }

        foreach ($pendingOffsets as $offset) {
            $followUps['pending'.$offset] = 'jobs/pending?starting_at='.($offset - 1);
        }

        foreach ($reuseRuntimes ? [] : $queues as $index => $queue) {
            $followUps['q'.$index] = 'metrics/queues/'.rawurlencode($queue);
        }

        $more = $followUps === [] || $this->millisecondsLeft($deadline) < 1
            ? []
            : $this->pool($target, $resolved, $followUps, max(1, $this->metricsConcurrency), $deadline);

        [$failedJobs, $failedJobsTruncated] = $failedPage === null
            ? [null, false]
            : $this->collect($failedPage, 'failed', $failedOffsets, $more);

        [$pendingJobs] = $pendingPage === null
            ? [null]
            : $this->collect($pendingPage, 'pending', $pendingOffsets, $more);

        [$queueRuntimes, $runtimesFetched] = $this->queueRuntimes($queues, $more, $knownRuntimes ?? []);

        return new HorizonReading(
            stats: $stats,
            masters: $masters,
            workload: $workload,
            failedJobs: $failedJobs === null ? null : $this->failedJobs($failedJobs),
            pendingJobs: $pendingJobs === null ? null : $this->pendingJobs($pendingJobs),
            queueRuntimes: $queueRuntimes,
            latencyMs: $this->latency($answers['stats'], $elapsed),
            failedJobsTruncated: $failedJobsTruncated,
            runtimesFetched: $runtimesFetched,
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
            throw new HorizonReadFailed($exception->reason, $exception->reason === ReadingError::Unreachable ? ReadingCause::Dns : null);
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
            ->withHeaders(['Accept-Encoding' => 'identity'])
            ->withOptions([
                'curl' => [CURLOPT_RESOLVE => [$resolved->curlResolve()]],
                'proxy' => ['no' => ['*']],
                'decode_content' => false,
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
            throw new HorizonReadFailed(ReadingError::NotHorizon, ReadingCause::TooLarge);
        }

        if (! $response instanceof Response || $watch->failed) {
            throw new HorizonReadFailed(ReadingError::Unreachable, ReadingCause::fromCurlError($watch->errno));
        }

        $status = $response->status();

        if ($status >= 500 || in_array($status, [408, 429], true)) {
            throw new HorizonReadFailed(ReadingError::Unreachable, ReadingCause::HttpStatus, $status);
        }

        if (in_array($status, [401, 403], true)) {
            throw new HorizonReadFailed(ReadingError::Unauthorized, ReadingCause::HttpStatus, $status);
        }

        if ($status >= 300 && $status < 400) {
            throw new HorizonReadFailed(ReadingError::NotHorizon, ReadingCause::Redirect, $status, $this->location($response));
        }

        if ($status !== 200) {
            throw new HorizonReadFailed(ReadingError::NotHorizon, $status === 404 ? ReadingCause::NotFound : ReadingCause::HttpStatus, $status);
        }

        if (! in_array(strtolower(trim($response->header('Content-Encoding'))), ['', 'identity'], true)) {
            throw new HorizonReadFailed(ReadingError::NotHorizon, ReadingCause::Compressed);
        }

        $size = $response->toPsrResponse()->getBody()->getSize();

        if ($size === null || $size > $this->maxBodyBytes) {
            throw new HorizonReadFailed(ReadingError::NotHorizon, ReadingCause::TooLarge);
        }

        $decoded = json_decode($response->body(), true);

        if (! is_array($decoded)) {
            throw new HorizonReadFailed(ReadingError::NotHorizon, ReadingCause::NotJson);
        }

        return $decoded;
    }

    /**
     * @param  array<string, array{response: mixed, watch: TransferWatch}>  $answers
     */
    private function worthRetrying(array $answers): bool
    {
        $transient = array_map(fn (string $key): ?bool => $this->transient($answers[$key]), self::MAIN_PATHS);

        return in_array(true, $transient, true) && ! in_array(false, $transient, true);
    }

    /**
     * @param  array{response: mixed, watch: TransferWatch}  $answer
     */
    private function transient(array $answer): ?bool
    {
        ['response' => $response, 'watch' => $watch] = $answer;

        if ($watch->tooLarge) {
            return false;
        }

        if (! $response instanceof Response || $watch->failed) {
            return ! in_array(ReadingCause::fromCurlError($watch->errno), [ReadingCause::Timeout, ReadingCause::Tls, ReadingCause::Dns], true);
        }

        if (in_array($response->status(), self::TRANSIENT_STATUSES, true)) {
            return true;
        }

        return $response->status() === 200 ? null : false;
    }

    private function location(Response $response): ?string
    {
        $location = (string) preg_replace('/[?#].*$/s', '', $response->header('Location'));
        $location = (string) preg_replace('#^([a-z][a-z0-9+.-]*://)[^/]*@#i', '$1', $location);
        $location = (string) preg_replace('/[^\x21-\x7e]/', '', $location);

        return $location === '' ? null : mb_substr($location, 0, self::MAX_NAME_LENGTH);
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
            throw new HorizonReadFailed(ReadingError::NotHorizon, ReadingCause::UnexpectedJson);
        }

        $wait = [];

        foreach (is_array($body['wait'] ?? null) ? array_slice($body['wait'], 0, $this->maxQueues, true) : [] as $queue => $seconds) {
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
                throw new HorizonReadFailed(ReadingError::NotHorizon, ReadingCause::UnexpectedJson);
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
            throw new HorizonReadFailed(ReadingError::NotHorizon, ReadingCause::UnexpectedJson);
        }

        $processes = [];

        foreach (is_array($supervisor['processes'] ?? null) ? array_slice($supervisor['processes'], 0, $this->maxQueues, true) : [] as $queue => $count) {
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
            throw new HorizonReadFailed(ReadingError::NotHorizon, ReadingCause::UnexpectedJson);
        }

        $queues = array_map(function (mixed $queue): HorizonQueueLoad {
            if (! is_array($queue) || ! is_string($queue['name'] ?? null)) {
                throw new HorizonReadFailed(ReadingError::NotHorizon, ReadingCause::UnexpectedJson);
            }

            return new HorizonQueueLoad(
                name: $this->name($queue['name']),
                length: $this->required($queue['length'] ?? null),
                wait: $this->required($queue['wait'] ?? null),
                processes: $this->required($queue['processes'] ?? null),
            );
        }, $body);

        if (count($queues) <= $this->maxQueues) {
            return $queues;
        }

        $lengths = array_map(fn (HorizonQueueLoad $queue): int => $queue->length, $queues);
        arsort($lengths);

        return array_values(array_intersect_key($queues, array_slice($lengths, 0, $this->maxQueues, true)));
    }

    /**
     * @param  array{response: mixed, watch: TransferWatch}  $answer
     * @return array{jobs: list<mixed>, total: int|null}
     */
    private function page(array $answer): array
    {
        $body = $this->body($answer);
        $jobs = $body['jobs'] ?? null;

        if (! is_array($jobs) || ! array_is_list($jobs)) {
            throw new HorizonReadFailed(ReadingError::NotHorizon, ReadingCause::UnexpectedJson);
        }

        return ['jobs' => $jobs, 'total' => $this->number($body['total'] ?? null)];
    }

    /**
     * @param  array{jobs: list<mixed>, total: int|null}  $page
     */
    private function endsInWindow(array $page): bool
    {
        $oldest = end($page['jobs']);
        $failedAt = is_array($oldest) ? $this->timestamp($oldest['failed_at'] ?? null) : null;

        return $failedAt !== null && $failedAt->gte(CarbonImmutable::now()->subMinutes($this->failedRateMinutes));
    }

    /**
     * @param  array{jobs: list<mixed>, total: int|null}  $page
     * @return list<int>
     */
    private function pageOffsets(array $page, bool $fromTheEnd): array
    {
        $size = count($page['jobs']);
        $total = $page['total'];

        if ($size === 0 || $total === null || $total <= $size) {
            return [];
        }

        $offsets = [];

        for ($offset = $fromTheEnd ? max($size, $total - ($this->maxJobPages - 1) * $size) : $size; $offset < $total && count($offsets) < $this->maxJobPages - 1; $offset += $size) {
            $offsets[] = $offset;
        }

        return $offsets;
    }

    /**
     * @param  array{jobs: list<mixed>, total: int|null}  $first
     * @param  list<int>  $offsets
     * @param  array<string, array{response: mixed, watch: TransferWatch}>  $answers
     * @return array{0: list<array{name: string, queue: string, job: array<array-key, mixed>}>, 1: bool}
     */
    private function collect(array $first, string $key, array $offsets, array $answers): array
    {
        $pages = [$first];
        $complete = true;

        foreach ($offsets as $offset) {
            $page = isset($answers[$key.$offset]) ? $this->secondary(fn () => $this->page($answers[$key.$offset])) : null;

            if ($page === null) {
                $complete = false;

                break;
            }

            $pages[] = $page;
        }

        $kept = [];
        $seen = [];
        $read = 0;

        foreach ($pages as $page) {
            $read += count($page['jobs']);

            foreach ($page['jobs'] as $job) {
                if (! is_array($job) || ! is_string($job['name'] ?? null) || ! is_string($job['queue'] ?? null)) {
                    continue;
                }

                $id = is_string($job['id'] ?? null) ? $job['id'] : null;

                if ($id !== null && isset($seen[$id])) {
                    continue;
                }

                if (count($kept) >= $this->maxJobs) {
                    return [$kept, true];
                }

                if ($id !== null) {
                    $seen[$id] = true;
                }

                $kept[] = ['name' => $this->name($job['name']), 'queue' => $this->name($job['queue']), 'job' => $job];
            }
        }

        return [$kept, ! $complete || $read < ($first['total'] ?? 0)];
    }

    /**
     * @param  list<array{name: string, queue: string, job: array<array-key, mixed>}>  $kept
     * @return list<HorizonFailedJob>
     */
    private function failedJobs(array $kept): array
    {
        $jobs = [];

        foreach ($kept as ['name' => $name, 'queue' => $queue, 'job' => $job]) {
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
     * @param  list<array{name: string, queue: string, job: array<array-key, mixed>}>  $kept
     * @return list<HorizonPendingJob>
     */
    private function pendingJobs(array $kept): array
    {
        $jobs = [];

        foreach ($kept as ['name' => $name, 'queue' => $queue, 'job' => $job]) {
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
     * @return list<string>
     */
    private function busiestQueues(array $workload): array
    {
        usort($workload, fn (HorizonQueueLoad $a, HorizonQueueLoad $b) => $b->length <=> $a->length);

        return array_slice(array_values(array_unique(array_map(
            fn (HorizonQueueLoad $queue): string => $queue->name,
            $workload,
        ))), 0, $this->maxMetricQueues);
    }

    /**
     * @param  list<string>  $queues
     * @param  array<string, array{response: mixed, watch: TransferWatch}>  $answers
     * @param  array<string, float|null>  $known
     * @return array{0: array<string, float>, 1: bool}
     */
    private function queueRuntimes(array $queues, array $answers, array $known): array
    {
        $runtimes = [];
        $answered = false;

        foreach ($queues as $index => $queue) {
            $snapshots = isset($answers['q'.$index]) ? $this->secondary(fn () => $this->body($answers['q'.$index])) : null;

            if ($snapshots === null || ! array_is_list($snapshots)) {
                $runtime = $known[$queue] ?? null;

                if ($runtime !== null) {
                    $runtimes[$queue] = (float) $runtime;
                }

                continue;
            }

            $answered = true;
            $last = end($snapshots);
            $runtime = is_array($last) ? $this->finite($last['runtime'] ?? null) : null;

            if ($runtime !== null) {
                $runtimes[$queue] = min(max($runtime, 0.0), (float) self::MAX_NUMBER);
            }
        }

        return [$runtimes, $answered];
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
        return $this->number($value) ?? throw new HorizonReadFailed(ReadingError::NotHorizon, ReadingCause::UnexpectedJson);
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
