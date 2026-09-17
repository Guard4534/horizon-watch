<?php

namespace App\Data\Pages;

use App\Data\Monitoring\AlertData;
use App\Data\Monitoring\EnvironmentData;
use App\Data\Monitoring\SentNotificationData;
use Spatie\LaravelData\Data;

class WallPageData extends Data
{
    public function __construct(
        public WallKpisData $kpis,
        /** @var array<int, EnvironmentData> */
        public array $environments,
        /** @var array<int, AlertData> */
        public array $anomalies,
        /** @var array<int, int> */
        public array $throughput,
        public int $jobsPerMinute,
        /** @var array<int, SentNotificationData> */
        public array $notifications,
        public int $applicationCount,
        // The default jobs.failed_per_hour threshold, so a tile colours its
        // failures by the same rate the evaluator uses, not by a count.
        public float $failedPerHourThreshold,
    ) {}
}
