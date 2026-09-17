<?php

namespace Database\Seeders\Support;

use App\Enums\EnvironmentStatus;
use App\Enums\HorizonStatus;
use App\Enums\ReadingError;
use App\Externals\Horizon\Data\HorizonFailedJob;
use App\Externals\Horizon\Data\HorizonMaster;
use App\Externals\Horizon\Data\HorizonPendingJob;
use App\Externals\Horizon\Data\HorizonQueueLoad;
use App\Externals\Horizon\Data\HorizonStats;
use App\Externals\Horizon\HorizonReading;
use App\Models\Environment;
use App\Models\EnvironmentSnapshot;
use App\Models\EnvironmentState;
use App\Monitoring\EvaluatedStatus;
use App\Monitoring\StatusEvaluator;
use Carbon\CarbonImmutable;

final class SyntheticReadings
{
    private const TICK_SECONDS = 15;

    private const CHUNK = 500;

    private const FAILED_WINDOW_MINUTES = 1440;

    public const INCIDENTS = [
        'fatturaomatic-production' => EnvironmentStatus::Inactive,
        'mailer-service-worker-batch' => EnvironmentStatus::Degraded,
        'logistics-hub-worker-batch' => EnvironmentStatus::Unreachable,
        'acme-shop-staging' => EnvironmentStatus::Paused,
        'media-encoder-production' => EnvironmentStatus::Degraded,
        'billing-sync-preprod' => EnvironmentStatus::Degraded,
    ];

    private const FAILED_JOBS = [
        ['App\\Jobs\\SendInvoiceMail', 'Illuminate\\Database\\QueryException: deadlock found'],
        ['App\\Jobs\\SyncCustomer', 'GuzzleHttp\\Exception\\ConnectException: cURL error 28'],
        ['App\\Jobs\\GenerateReport', 'RedisException: read error on connection'],
        ['App\\Jobs\\PushWebhook', 'Symfony\\Component\\Mailer\\Exception\\TransportException'],
        ['App\\Jobs\\RebuildIndex', 'App\\Exceptions\\PayloadTooLarge'],
    ];

    private const RESERVED_JOBS = ['App\\Jobs\\RebuildIndex', 'App\\Jobs\\ExportLedger', 'App\\Jobs\\TranscodeVideo'];

    public function __construct(
        private readonly StatusEvaluator $evaluator = new StatusEvaluator,
    ) {}

    public function seed(Environment $environment, CarbonImmutable $until, int $hours = 24, int $stepMinutes = 5): void
    {
        $count = intdiv($hours * 60, $stepMinutes);
        $model = new EnvironmentSnapshot;
        $rows = [];

        for ($index = $count - 1; $index >= 0; $index--) {
            $capturedAt = $until->subMinutes($index * $stepMinutes);
            $rows[] = ['captured_at' => $model->fromDateTime($capturedAt)] + $this->snapshotRow($environment, $capturedAt);

            if (count($rows) === self::CHUNK) {
                EnvironmentSnapshot::query()->insert($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            EnvironmentSnapshot::query()->insert($rows);
        }

        $this->writeState($environment, $until, $until->subMinutes($count * $stepMinutes));
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotRow(Environment $environment, CarbonImmutable $capturedAt): array
    {
        $row = ['environment_id' => $environment->id];

        if ($this->incident($environment) === EnvironmentStatus::Unreachable) {
            return $row + [
                'status' => EnvironmentStatus::Unreachable->value,
                'error' => ReadingError::Unreachable->value,
                'breaches' => $this->encodeBreaches($this->evaluator->failed()),
                'pending' => 0,
                'max_wait_seconds' => 0,
                'jobs_per_minute' => 0,
                'failed_in_window' => 0,
                'failed_window_minutes' => self::FAILED_WINDOW_MINUTES,
                'failed_last_hour' => 0,
                'workers' => 0,
                'node_count' => 0,
                'latency_ms' => null,
            ];
        }

        $reading = $this->reading($environment, $capturedAt, $this->incident($environment));
        $evaluated = $this->evaluator->evaluate($reading['horizon']);

        return $row + [
            'status' => $evaluated->status->value,
            'error' => null,
            'breaches' => $this->encodeBreaches($evaluated),
            'pending' => array_sum(array_column($reading['queues'], 'pending')),
            'max_wait_seconds' => max([0, ...array_column($reading['queues'], 'waitSeconds')]),
            'jobs_per_minute' => $reading['horizon']->stats->jobsPerMinute,
            'failed_in_window' => $reading['horizon']->stats->failedJobs,
            'failed_window_minutes' => $reading['horizon']->stats->failedJobsPeriodMinutes,
            'failed_last_hour' => $evaluated->failedLastHour,
            'workers' => $reading['horizon']->stats->processes,
            'node_count' => count($reading['nodes']),
            'latency_ms' => $reading['horizon']->latencyMs,
        ];
    }

    private function writeState(Environment $environment, CarbonImmutable $until, CarbonImmutable $beforeWindow): void
    {
        $incident = $this->incident($environment);
        $unreachable = $incident === EnvironmentStatus::Unreachable;
        $detailAt = $unreachable ? $beforeWindow : $until;
        $reading = $unreachable
            ? $this->reading($environment, $detailAt, EnvironmentStatus::Degraded)
            : $this->reading($environment, $detailAt, $incident);
        $evaluated = $unreachable
            ? $this->evaluator->failed()
            : $this->evaluator->evaluate($reading['horizon']);

        EnvironmentState::query()->updateOrCreate(
            ['environment_id' => $environment->id],
            [
                'captured_at' => $until,
                'status' => $evaluated->status,
                'error' => $unreachable ? ReadingError::Unreachable : null,
                'horizon_status' => HorizonStatus::from($reading['horizon']->stats->status),
                'nodes' => $reading['nodes'],
                'queues' => $reading['queues'],
                'failed_jobs' => $this->failedJobs($environment, $detailAt),
                'pending_jobs' => array_map(
                    fn (array $job) => ['job' => $job['job'], 'queue' => $job['queue'], 'reservedAt' => $detailAt->subSeconds($job['elapsed'])->toIso8601String()],
                    $reading['reserved'],
                ),
                'latency_ms' => $unreachable ? null : $reading['horizon']->latencyMs,
            ],
        );
    }

    /**
     * @return array{
     *     horizon: HorizonReading,
     *     nodes: list<array{hostname: string, status: string, workers: int, supervisors: int, queues: int, seenAt: string}>,
     *     queues: list<array{name: string, supervisor: string|null, workers: int, pending: int, waitSeconds: int, runtimeSeconds: float|null}>,
     *     reserved: list<array{job: string, queue: string, elapsed: int}>,
     * }
     */
    private function reading(Environment $environment, CarbonImmutable $at, EnvironmentStatus $status): array
    {
        $slug = $environment->slug;
        $r = $this->unit($slug);
        $r2 = $this->unit($slug.'x');
        $production = $environment->name === 'production';
        $tick = intdiv($at->getTimestamp(), self::TICK_SECONDS);
        $drift = sin($tick / 4 + $r * 9) * 0.5 + 0.5;
        $cycle = $tick % 40;
        $down = $status->isDown();

        [$pending, $wait] = match (true) {
            $down => [(int) round(2800 + $r * 2600 + $cycle * 11), (int) round(780 + $r2 * 500 + $cycle * 3)],
            $status === EnvironmentStatus::Paused => [(int) round(900 + $r * 500), (int) round(300 + $r2 * 200)],
            $status === EnvironmentStatus::Degraded => [(int) round(1400 + $r * 1800 * (0.7 + $drift)), (int) round(120 + $r2 * 260 * (0.6 + $drift))],
            default => [
                (int) round(($production ? 180 + $r * 900 : 12 + $r * 220) * (0.6 + $drift * 0.9)),
                (int) round(($production ? 4 : 2) + $r2 * 40 * (0.4 + $drift)),
            ],
        };
        $workers = $down ? 0 : (int) round(3 + $r * 29);
        $jobsPerMinute = $down ? 0 : (int) round(($production ? 140 : 20) * (0.5 + $drift) + $r * 40);
        $failed = $status->isHealthy() ? (int) round($r2 * 6) : (int) round(14 + $r2 * 90 + $cycle);

        $queues = $this->queues($environment, $status, $pending, $wait, $workers);
        $nodes = $status === EnvironmentStatus::Inactive ? [] : $this->nodes($environment, $status, $workers, count($queues), $at);
        $reserved = $this->reserved($environment, $status);

        $horizon = new HorizonReading(
            stats: new HorizonStats(
                status: match ($status) {
                    EnvironmentStatus::Inactive => 'inactive',
                    EnvironmentStatus::Paused => 'paused',
                    default => 'running',
                },
                jobsPerMinute: $jobsPerMinute,
                failedJobs: $failed,
                processes: $workers,
                pausedMasters: $status === EnvironmentStatus::Paused ? count($nodes) : 0,
                wait: [],
                failedJobsPeriodMinutes: self::FAILED_WINDOW_MINUTES,
            ),
            masters: array_map(fn (array $node) => new HorizonMaster($node['hostname'], $node['status'], []), $nodes),
            workload: array_map(fn (array $queue) => new HorizonQueueLoad($queue['name'], $queue['pending'], $queue['waitSeconds'], $queue['workers']), $queues),
            failedJobs: array_map(fn (array $job) => new HorizonFailedJob($job['job'], $job['queue'], $job['exception'], $job['tries'], CarbonImmutable::parse($job['failedAt'])), $this->failedJobs($environment, CarbonImmutable::now())),
            pendingJobs: array_map(fn (array $job) => new HorizonPendingJob($job['job'], $job['queue'], 'reserved', CarbonImmutable::now()->subSeconds($job['elapsed'])), $reserved),
            queueRuntimes: [],
            latencyMs: (int) round(90 + $r2 * 600),
        );

        return ['horizon' => $horizon, 'nodes' => $nodes, 'queues' => $queues, 'reserved' => $reserved];
    }

    /**
     * @return list<array{name: string, supervisor: string|null, workers: int, pending: int, waitSeconds: int, runtimeSeconds: float|null}>
     */
    private function queues(Environment $environment, EnvironmentStatus $status, int $pending, int $maxWait, int $workers): array
    {
        $shares = [0.42, 0.24, 0.16, 0.11, 0.07];
        $down = $status->isDown();
        $queues = [];

        foreach ($this->queueNames($environment) as $index => $name) {
            $r = $this->unit($environment->slug.$name);

            $queues[] = [
                'name' => $name,
                'supervisor' => "{$environment->name}-supervisor-".($index < 2 ? 1 : 2),
                'workers' => $down ? 0 : max(1, (int) round($workers * $shares[$index])),
                'pending' => (int) round($pending * $shares[$index]),
                'waitSeconds' => $down ? $maxWait : (int) round($maxWait * (0.5 + $r / 2)),
                'runtimeSeconds' => round(0.4 + $r * 6, 1),
            ];
        }

        return $queues;
    }

    /**
     * @return list<array{hostname: string, status: string, workers: int, supervisors: int, queues: int, seenAt: string}>
     */
    private function nodes(Environment $environment, EnvironmentStatus $status, int $workers, int $queueCount, CarbonImmutable $at): array
    {
        $prefixes = match ($environment->name) {
            'production' => ['queue-01', 'queue-02', 'queue-03'],
            'worker-batch' => ['batch-01', 'batch-02'],
            default => ['app-01'],
        };
        $divisor = $environment->name === 'production' ? 3 : 2;
        $applicationSlug = $environment->application->slug;
        $nodes = [];

        foreach ($prefixes as $index => $prefix) {
            $nodes[] = [
                'hostname' => "{$prefix}.{$applicationSlug}.internal",
                'status' => $status === EnvironmentStatus::Paused ? 'paused' : 'running',
                'workers' => max(1, (int) round($workers / $divisor)),
                'supervisors' => $index === 0 ? 2 : 1,
                'queues' => min($queueCount, 2 + $index),
                'seenAt' => $at->toIso8601String(),
            ];
        }

        return $nodes;
    }

    /**
     * @return list<array{job: string, queue: string, elapsed: int}>
     */
    private function reserved(Environment $environment, EnvironmentStatus $status): array
    {
        if ($status->isHealthy()) {
            return [];
        }

        $queueNames = $this->queueNames($environment);
        $jobs = [];

        foreach (self::RESERVED_JOBS as $index => $job) {
            $jobs[] = [
                'job' => $job,
                'queue' => $queueNames[$index % count($queueNames)],
                'elapsed' => $status->isDown() ? 780 + $index * 260 : 96 + $index * 130,
            ];
        }

        return $jobs;
    }

    /**
     * @return list<array{job: string, queue: string, exception: string, tries: int, failedAt: string}>
     */
    private function failedJobs(Environment $environment, CarbonImmutable $until): array
    {
        $queueNames = $this->queueNames($environment);
        $failed = [];

        foreach (self::FAILED_JOBS as $index => [$job, $exception]) {
            $failed[] = [
                'job' => $job,
                'queue' => $queueNames[$index % count($queueNames)],
                'exception' => $exception,
                'tries' => $index % 3 + 1,
                'failedAt' => $until->subMinutes(2 + $index * 7)->toIso8601String(),
            ];
        }

        return $failed;
    }

    private function incident(Environment $environment): EnvironmentStatus
    {
        return self::INCIDENTS[$environment->slug]
            ?? ($this->unit($environment->slug) > 0.93 ? EnvironmentStatus::Degraded : EnvironmentStatus::Active);
    }

    /**
     * @return list<string>
     */
    private function queueNames(Environment $environment): array
    {
        return $environment->name === 'worker-batch'
            ? ['batch-import', 'batch-export', 'default']
            : ['default', 'emails', 'notifications', 'invoices', 'webhooks'];
    }

    private function encodeBreaches(EvaluatedStatus $evaluated): string
    {
        return json_encode(array_map(fn ($metric) => $metric->value, $evaluated->breaches), JSON_THROW_ON_ERROR);
    }

    private function unit(string $seed): float
    {
        $hash = 2166136261;

        foreach (str_split($seed) as $character) {
            $hash ^= ord($character);
            $hash = ($hash * 16777619) & 0xFFFFFFFF;
        }

        return $hash / 4294967296;
    }
}
