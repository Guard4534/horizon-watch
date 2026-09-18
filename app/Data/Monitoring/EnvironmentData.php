<?php

namespace App\Data\Monitoring;

use App\Enums\EnvironmentColor;
use App\Enums\EnvironmentStatus;
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
        public EnvironmentColor $color,
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
        public int $workers,
        public int $jobsPerMinute,
        public int $nodeCount,
        public ?string $basicAuthUser,
        public ?int $latencyMs,
        public bool $watched,
        public ?string $lastReadingAt,
        public bool $stale,
        public bool $pollingEnabled,
        public int $pollIntervalSeconds,
        public ?ReadingError $readingError,
        public ?HorizonStatus $horizonStatus,
        /** @var array<string, float> */
        public array $thresholds,
    ) {}

    public static function compareBySeverityThenPending(self $a, self $b): int
    {
        return [$a->severity(), $b->pending] <=> [$b->severity(), $a->pending];
    }

    public function severity(): int
    {
        return $this->status === null
            ? 2 * EnvironmentStatus::Degraded->severity() + 1
            : 2 * $this->status->severity();
    }
}
