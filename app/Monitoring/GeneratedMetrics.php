<?php

namespace App\Monitoring;

use App\Data\Monitoring\FailedJobData;
use App\Data\Monitoring\LongRunningJobData;
use App\Data\Monitoring\NodeData;
use App\Data\Monitoring\QueueData;
use App\Enums\EnvironmentStatus;
use App\Enums\SeriesRange;
use App\Models\Environment;
use Carbon\CarbonImmutable;

/**
 * Stands in for real Horizon readings until polling exists (phase 3).
 * Everything here is a pure function of an environment's slug/name and the
 * current 15-second tick: same tick, same numbers, for every caller.
 *
 * Extracted from the phase 1 FakeMonitoringRepository, which also owned the
 * fake list of applications; that part is gone; only the number generator
 * remains, now fed real Environment models by ConfiguredMonitoringRepository.
 */
class GeneratedMetrics
{
    private const TICK_SECONDS = 15;

    private const SERIES_POINTS = 48;

    // Fixed incidents, keyed by environment slug (not by name: two
    // applications can each have a "production"), so the wall always tells
    // the same story while the rest drifts. Unchanged from phase 1 — the
    // seeder creates the same applications with the same slugs on purpose.
    private const INCIDENTS = [
        'fatturaomatic-production' => EnvironmentStatus::Inactive,
        'mailer-service-worker-batch' => EnvironmentStatus::Degraded,
        'logistics-hub-worker-batch' => EnvironmentStatus::Unreachable,
        'acme-shop-staging' => EnvironmentStatus::Paused,
        'media-encoder-production' => EnvironmentStatus::Degraded,
        'billing-sync-preprod' => EnvironmentStatus::Degraded,
    ];

    /**
     * The deterministic metrics for one environment at the current tick.
     */
    public function metricsFor(Environment $environment): EnvironmentMetrics
    {
        $id = $environment->slug;
        $r = $this->unit($id);
        $r2 = $this->unit($id.'x');
        $production = $environment->name === 'production';
        $tick = $this->tick();
        $drift = sin($tick / 4 + $r * 9) * 0.5 + 0.5;
        // The mockup lets incidents grow for ever; a bounded cycle keeps real clocks sane.
        $cycle = $tick % 40;
        $status = self::INCIDENTS[$id] ?? ($r > 0.93 ? EnvironmentStatus::Degraded : EnvironmentStatus::Active);

        [$pending, $wait] = match (true) {
            $status->isDown() => [(int) round(2800 + $r * 2600 + $cycle * 11), (int) round(780 + $r2 * 500 + $cycle * 3)],
            $status === EnvironmentStatus::Paused => [(int) round(900 + $r * 500), (int) round(300 + $r2 * 200)],
            $status === EnvironmentStatus::Degraded => [(int) round(1400 + $r * 1800 * (0.7 + $drift)), (int) round(120 + $r2 * 260 * (0.6 + $drift))],
            default => [
                (int) round(($production ? 180 + $r * 900 : 12 + $r * 220) * (0.6 + $drift * 0.9)),
                (int) round(($production ? 4 : 2) + $r2 * 40 * (0.4 + $drift)),
            ],
        };

        return new EnvironmentMetrics(
            status: $status,
            pending: $pending,
            maxWaitSeconds: $wait,
            failedLast24Hours: $status->isHealthy() ? (int) round($r2 * 6) : (int) round(14 + $r2 * 90 + $cycle),
            workers: $status->isDown() ? 0 : (int) round(3 + $r * 29),
            jobsPerMinute: $status->isDown() ? 0 : (int) round(($production ? 140 : 20) * (0.5 + $drift) + $r * 40),
            nodeCount: match ($environment->name) {
                'production' => 3,
                'worker-batch' => 2,
                default => 1,
            },
            redisMemoryGb: round(0.6 + $r * 3.4, 1),
            latencyMs: (int) round(90 + $r2 * 600),
        );
    }

    /**
     * @return array<int, NodeData>
     */
    public function nodes(Environment $environment, EnvironmentMetrics $metrics): array
    {
        $prefixes = match ($environment->name) {
            'production' => ['queue-01', 'queue-02', 'queue-03'],
            'worker-batch' => ['batch-01', 'batch-02'],
            default => ['app-01'],
        };
        $down = $metrics->status->isDown();
        $divisor = $environment->name === 'production' ? 3 : 2;
        $queueCount = count($this->queueNames($environment));
        $applicationSlug = $environment->application->slug;
        $nodes = [];

        foreach ($prefixes as $index => $prefix) {
            $nodeDown = $down && $index === 0;

            $nodes[] = new NodeData(
                hostname: "{$prefix}.{$applicationSlug}.internal",
                status: $nodeDown ? $metrics->status : ($down ? EnvironmentStatus::Degraded : $metrics->status),
                workers: $nodeDown ? 0 : max(1, (int) round($metrics->workers / $divisor)),
                jobsPerMinute: $nodeDown ? 0 : (int) round($metrics->jobsPerMinute / $divisor),
                memoryMb: (int) round(120 + $this->unit($environment->slug.$prefix) * 260),
                supervisorCount: $index === 0 ? 2 : 1,
                queueCount: $nodeDown ? 0 : min($queueCount, 2 + $index),
                lastHeartbeatSecondsAgo: $nodeDown ? 840 : 2,
            );
        }

        return $nodes;
    }

    /**
     * @return array<int, QueueData>
     */
    public function queues(Environment $environment, EnvironmentMetrics $metrics): array
    {
        $shares = [0.42, 0.24, 0.16, 0.11, 0.07];
        $down = $metrics->status->isDown();
        $queues = [];

        foreach ($this->queueNames($environment) as $index => $name) {
            $r = $this->unit($environment->slug.$name);
            $wait = $down ? $metrics->maxWaitSeconds : (int) round($metrics->maxWaitSeconds * (0.5 + $r));

            $queues[] = new QueueData(
                name: $name,
                supervisor: "{$environment->name}-supervisor-".($index < 2 ? 1 : 2),
                workers: $down ? 0 : max(1, (int) round($metrics->workers * $shares[$index])),
                pending: (int) round($metrics->pending * $shares[$index]),
                waitSeconds: $wait,
                runtimeSeconds: round(0.4 + $r * 6, 1),
                status: match (true) {
                    $down => EnvironmentStatus::Inactive,
                    $wait >= 300 => EnvironmentStatus::Degraded,
                    $metrics->status === EnvironmentStatus::Paused => EnvironmentStatus::Paused,
                    default => EnvironmentStatus::Active,
                },
            );
        }

        return $queues;
    }

    /**
     * @return array<int, FailedJobData>
     */
    public function failedJobs(Environment $environment): array
    {
        $jobs = ['App\\Jobs\\SendInvoiceMail', 'App\\Jobs\\SyncCustomer', 'App\\Jobs\\GenerateReport', 'App\\Jobs\\PushWebhook', 'App\\Jobs\\RebuildIndex'];
        $exceptions = [
            'Illuminate\\Database\\QueryException: deadlock found',
            'GuzzleHttp\\Exception\\ConnectException: cURL error 28',
            'RedisException: read error on connection',
            'Symfony\\Component\\Mailer\\Exception\\TransportException',
            'App\\Exceptions\\PayloadTooLarge',
        ];
        $queueNames = $this->queueNames($environment);
        $failed = [];

        foreach ($jobs as $index => $job) {
            $failed[] = new FailedJobData(
                job: $job,
                queue: $queueNames[$index % count($queueNames)],
                exception: $exceptions[$index],
                tries: $index % 3 + 1,
                minutesAgo: 2 + $index * 7,
            );
        }

        return $failed;
    }

    /**
     * @return array<int, LongRunningJobData>
     */
    public function longRunningJobs(Environment $environment, EnvironmentMetrics $metrics): array
    {
        $jobs = ['App\\Jobs\\RebuildIndex', 'App\\Jobs\\ExportLedger', 'App\\Jobs\\TranscodeVideo'];
        $queueNames = $this->queueNames($environment);
        $running = [];

        foreach ($jobs as $index => $job) {
            $elapsed = $metrics->status->isDown() ? 780 + $index * 260 : 96 + $index * 130;

            $running[] = new LongRunningJobData(
                job: $job,
                queue: $queueNames[$index % count($queueNames)],
                elapsedSeconds: $elapsed,
                // Quantized to the tick, like every other reading: an unquantized
                // now() would format differently across a minute boundary even
                // within the same 15s tick, breaking "same tick, same numbers".
                startedAt: $this->quantizedNow()->subSeconds($elapsed)->format('H:i'),
            );
        }

        return $running;
    }

    /**
     * Jobs per minute across the range; a null slug means the whole organization.
     *
     * @return array<int, int>
     */
    public function throughputSeries(?string $environmentSlug, SeriesRange $range): array
    {
        $seed = 'tp'.($environmentSlug ?? 'organization').$range->value.intdiv($this->tick(), 2);

        return $environmentSlug === null
            ? $this->series($seed, 60, 70)
            : $this->series($seed, 70, 30);
    }

    /**
     * @return array<int, int>
     */
    public function maxWaitSeries(string $environmentSlug, bool $down, SeriesRange $range): array
    {
        return $this->series('wt'.$environmentSlug.$range->value.intdiv($this->tick(), 2), $down ? 95 : 30, 8);
    }

    /**
     * The current 15-second tick: the seam every reading here holds still
     * across, and moves on the next.
     */
    public function tick(): int
    {
        return intdiv(now()->getTimestamp(), self::TICK_SECONDS);
    }

    /**
     * "Now", rounded down to the current tick, so anything formatted from it
     * (e.g. a job's start time) stays identical for the whole 15s window.
     */
    public function quantizedNow(): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestamp($this->tick() * self::TICK_SECONDS);
    }

    /**
     * @return array<int, string>
     */
    private function queueNames(Environment $environment): array
    {
        return $environment->name === 'worker-batch'
            ? ['batch-import', 'batch-export', 'default']
            : ['default', 'emails', 'notifications', 'invoices', 'webhooks'];
    }

    /**
     * @return array<int, int>
     */
    private function series(string $seed, float $amplitude, float $base): array
    {
        $phase = $this->unit($seed) * 6;
        $values = [];

        for ($i = 0; $i < self::SERIES_POINTS; $i++) {
            $values[] = (int) round($base + abs(sin($i / 3 + $phase)) * $amplitude + $this->unit($seed.$i) * $amplitude * 0.5);
        }

        return $values;
    }

    /**
     * FNV-1a folded into [0, 1): the same seed always gives the same number.
     */
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
