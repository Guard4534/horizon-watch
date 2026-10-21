<?php

namespace App\Data\Monitoring;

use App\Enums\EnvironmentStatus;
use App\Enums\EnvironmentType;
use App\Enums\HorizonStatus;
use App\Enums\ReadingError;
use Spatie\LaravelData\Data;

class EnvironmentData extends Data
{
    public function __construct(
        public string $id,
        public string $applicationId,
        public string $applicationName,
        public string $name,
        public EnvironmentType $type,
        public string $horizonUrl,
        public ?EnvironmentStatus $status,
        public int $pending,
        /** @var array<int, int> */
        public array $trend,
        public ?int $trendPercent,
        public int $maxWaitSeconds,
        public int $failedInWindow,
        public int $failedWindowMinutes,
        public int $failedLastHour,
        public bool $failedLastHourCapped,
        public int $workers,
        public int $jobsPerMinute,
        public int $nodeCount,
        public bool $usesBasicAuth,
        public ?int $latencyMs,
        public bool $watched,
        public ?string $lastReadingAt,
        public bool $stale,
        public bool $pollingEnabled,
        public int $pollIntervalSeconds,
        public ?ReadingError $readingError,
        public ?ReadingProblemData $readingProblem,
        public ?HorizonStatus $horizonStatus,
        /** @var array<string, ThresholdData> */
        public array $thresholds,
        public ?string $maintenanceUntil,
        public ?string $maintenanceReason,
    ) {}

    public static function compareBySeverityThenPending(self $a, self $b): int
    {
        return [$a->severity(), $b->pending] <=> [$b->severity(), $a->pending];
    }

    public function severity(): int
    {
        return $this->status === null || ! $this->pollingEnabled
            ? 2 * EnvironmentStatus::Degraded->severity() + 1
            : 2 * $this->status->severity();
    }

    public function isJudged(): bool
    {
        return $this->watched && $this->pollingEnabled && $this->status !== null;
    }

    public function isHealthy(): bool
    {
        return $this->isJudged() && $this->status?->isHealthy() === true;
    }

    public function needsAttention(): bool
    {
        return $this->isJudged() && $this->status?->isHealthy() === false;
    }
}
