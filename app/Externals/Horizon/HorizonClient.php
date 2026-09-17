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
use Closure;
use GuzzleHttp\Psr7\Exception\MalformedUriException;
use Illuminate\Container\Attributes\Config;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Nothing is logged here, and no failure carries more than a ReadingError:
 * HTTP client exceptions hold the URL and sometimes the body, so they are
 * dropped rather than chained.
 *
 * Nothing Horizon sends may throw anything else out of the client either: a
 * bad value in a main call is NotHorizon, a bad job is skipped, a bad
 * secondary section is null. Every number that leaves is finite and
 * saturated into what an integer column holds, so absurd numbers still make
 * a reading instead of a failed insert on every poll.
 */
final readonly class HorizonClient implements HorizonReader
{
    private const array MASTER_STATUSES = ['running', 'paused', 'inactive'];

    /** Horizon's own answers are a few kilobytes; its job pages stay far below this. */
    private const int MAX_BODY_BYTES = 2 * 1024 * 1024;

    /** PostgreSQL's integer. */
    private const int MAX_NUMBER = 2_147_483_647;

    /** A string column's usual width; Horizon names are far shorter. */
    private const int MAX_NAME_LENGTH = 255;

    /**
     * What one reading keeps. Horizon pages jobs by 50, so the job caps only
     * bite on something that is not Horizon; a real installation with more
     * than 200 masters, or supervisors per master, is not one this panel
     * shows node by node anyway.
     */
    private const int MAX_MASTERS = 200;

    private const int MAX_SUPERVISORS = 200;

    private const int MAX_JOBS = 200;

    /**
     * Runtime is asked for the busiest queues only, and a few at a time: one
     * request per queue with no bound opened a socket per queue at once.
     * The other queues simply have no runtime.
     */
    private const int MAX_METRIC_QUEUES = 100;

    private const int METRICS_CONCURRENCY = 10;

    /** Horizon's own default for horizon.trim.failed, in minutes. */
    private const int HORIZON_DEFAULT_FAILED_WINDOW = 10080;

    /** Year 9999: a later instant has no ISO-8601 form the pages can read. */
    private const int MAX_TIMESTAMP = 253_402_300_799;

    public function __construct(
        private SafeUrlGuard $guard,
        #[Config('horizon-watch.http_timeout_seconds', 5)]
        private int $timeoutSeconds = 5,
    ) {}

    public function read(HorizonTarget $target): HorizonReading
    {
        $resolved = $this->guard->check($target->apiUrl('stats'));

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
            queueRuntimes: $this->queueRuntimes($target, $resolved, $workload),
            latencyMs: $this->latency($answers['stats'], $elapsed),
        );
    }

    public function probe(HorizonTarget $target): HorizonProbe
    {
        $resolved = $this->guard->check($target->apiUrl('stats'));

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
     * @param  array<string, string>  $paths  API path by pool key
     * @param  positive-int  $concurrency
     * @return array<string, array{response: mixed, watch: TransferWatch}>
     *
     * @throws HorizonReadFailed
     */
    private function pool(HorizonTarget $target, ResolvedTarget $resolved, array $paths, int $concurrency): array
    {
        $watches = array_map(fn () => new TransferWatch(self::MAX_BODY_BYTES), $paths);

        try {
            $responses = Http::pool(function (Pool $pool) use ($target, $resolved, $paths, $watches) {
                foreach ($paths as $key => $path) {
                    $this->get($pool->as($key), $target, $resolved, $path, $watches[$key]);
                }
            }, $concurrency);
        } catch (MalformedUriException) {
            // The guard refuses what it can see; a URL Guzzle still cannot
            // build is not an address this panel can use either.
            throw new HorizonReadFailed(ReadingError::Blocked);
        }

        $answers = [];

        foreach ($paths as $key => $path) {
            $answers[$key] = ['response' => $responses[$key] ?? null, 'watch' => $watches[$key]];
        }

        return $answers;
    }

    /**
     * CURLOPT_RESOLVE makes cURL connect to the address the guard checked,
     * so a DNS answer that changes between the check and the request cannot
     * redirect it. A proxy would resolve the name itself and undo that, so
     * proxies from the environment are bypassed.
     */
    private function get(PendingRequest $request, HorizonTarget $target, ResolvedTarget $resolved, string $path, TransferWatch $watch): void
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

        if ($target->hasBasicAuth()) {
            // Laravel's HTTP client events (RequestSending, ResponseReceived)
            // carry this Authorization header and the URL: a listener on
            // them must never log or store the request.
            $request->withBasicAuth((string) $target->username, (string) $target->password());
        }

        $request->get($target->apiUrl($path));
    }

    /**
     * Only the ReadingError survives, whatever the transfer ended in.
     *
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

        // The size is checked again on the buffered body, before reading
        // it: a handler that ignores the progress callback must not get an
        // oversized string into memory either.
        $size = $response->toPsrResponse()->getBody()->getSize();

        if ($status !== 200 || $size === null || $size > self::MAX_BODY_BYTES) {
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

    /**
     * Horizon states the window it counts failed jobs over as its trim
     * setting, and states null when the application sets neither trim key;
     * it then still counts over its default, 10080 minutes (its dashboard
     * says "Past 7 Days"). A missing or unusable window is read the same
     * way. Out of range is unusable here rather than saturated: a window is
     * not a count, and a clamped one would be a made-up length.
     */
    private function failedJobsPeriod(mixed $periods): int
    {
        $minutes = is_array($periods) ? $this->finite($periods['failedJobs'] ?? null) : null;

        if ($minutes === null || $minutes < 1 || $minutes > self::MAX_NUMBER) {
            return self::HORIZON_DEFAULT_FAILED_WINDOW;
        }

        return (int) round($minutes);
    }

    /**
     * Horizon keys masters by name, and answers an empty list, not an empty
     * object, when there are none.
     *
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

            if (count($masters) < self::MAX_MASTERS) {
                $masters[] = new HorizonMaster(
                    name: $this->name($master['name']),
                    status: $this->name($master['status']),
                    supervisors: array_map(
                        $this->supervisor(...),
                        array_slice($master['supervisors'], 0, self::MAX_SUPERVISORS),
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
     * Every queue is kept: the pending total and the longest wait are
     * measured over all of them, and the body cap already bounds the list.
     *
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
            if (count($kept) < self::MAX_JOBS && is_array($job) && is_string($job['name'] ?? null) && is_string($job['queue'] ?? null)) {
                $kept[] = ['name' => $this->name($job['name']), 'queue' => $this->name($job['queue']), 'job' => $job];
            }
        }

        return $kept;
    }

    /**
     * Only the first line of the exception is kept, and nothing of the
     * payload but the attempt count: both may hold whatever the application
     * put in them. A job without a usable failure time is skipped.
     *
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
                exception: Str::limit(trim(Str::before(is_string($job['exception'] ?? null) ? $job['exception'] : '', "\n")), 200, ''),
                attempts: $attempts ?? 0,
                failedAt: $failedAt,
            );
        }

        return $jobs;
    }

    /**
     * A job that is not reserved has no reservation time; one whose time is
     * present but unusable is skipped.
     *
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
     * Horizon already divides the snapshot runtime by 1000, so the value is
     * in seconds. The last snapshot is the most recent one.
     *
     * @param  list<HorizonQueueLoad>  $workload
     * @return array<string, float>
     */
    private function queueRuntimes(HorizonTarget $target, ResolvedTarget $resolved, array $workload): array
    {
        usort($workload, fn (HorizonQueueLoad $a, HorizonQueueLoad $b) => $b->length <=> $a->length);

        $queues = array_slice(array_values(array_unique(array_map(
            fn (HorizonQueueLoad $queue): string => $queue->name,
            $workload,
        ))), 0, self::MAX_METRIC_QUEUES);

        if ($queues === []) {
            return [];
        }

        $paths = [];

        foreach ($queues as $index => $queue) {
            $paths['q'.$index] = 'metrics/queues/'.rawurlencode($queue);
        }

        $answers = $this->pool($target, $resolved, $paths, self::METRICS_CONCURRENCY);
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
     * @throws HorizonReadFailed
     */
    private function required(mixed $value): int
    {
        return $this->number($value) ?? throw new HorizonReadFailed(ReadingError::NotHorizon);
    }

    /**
     * A finite number, rounded and saturated into [0, 2^31 - 1]; null for
     * anything else. The clamp happens on the float: PHP 8.5 refuses to
     * cast an unrepresentable float to int.
     */
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
     * The transfer time of the stats request when cURL reports it; the
     * duration of the whole pool otherwise, which is never shorter.
     *
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
