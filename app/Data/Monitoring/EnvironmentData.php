<?php

namespace App\Data\Monitoring;

use App\Enums\EnvironmentColor;
use App\Enums\EnvironmentStatus;
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
        public EnvironmentStatus $status,
        public int $pending,
        public int $maxWaitSeconds,
        public int $failedLast24Hours,
        public int $workers,
        public int $jobsPerMinute,
        public int $nodeCount,
        public ?string $basicAuthUser,
        public float $redisMemoryGb,
        public int $latencyMs,
    ) {}
}
