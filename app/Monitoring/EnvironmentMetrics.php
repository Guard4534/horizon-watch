<?php

namespace App\Monitoring;

use App\Enums\EnvironmentStatus;

/**
 * The numbers GeneratedMetrics computes for one environment at the current
 * tick. Internal to App\Monitoring: ConfiguredMonitoringRepository folds
 * this into EnvironmentData together with the environment's real
 * configuration (application, color, URL, …); nothing outside this
 * namespace should depend on this shape.
 */
final class EnvironmentMetrics
{
    public function __construct(
        public readonly EnvironmentStatus $status,
        public readonly int $pending,
        public readonly int $maxWaitSeconds,
        public readonly int $failedLast24Hours,
        public readonly int $workers,
        public readonly int $jobsPerMinute,
        public readonly int $nodeCount,
        public readonly float $redisMemoryGb,
        public readonly int $latencyMs,
    ) {}
}
