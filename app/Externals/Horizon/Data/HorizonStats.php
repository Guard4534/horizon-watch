<?php

namespace App\Externals\Horizon\Data;

final readonly class HorizonStats
{
    public const int DEFAULT_FAILED_WINDOW_MINUTES = 10080;

    /**
     * @param  array<string, int>  $wait
     */
    public function __construct(
        public string $status,
        public int $jobsPerMinute,
        public int $failedJobs,
        public int $processes,
        public int $pausedMasters,
        public array $wait,
        public int $failedJobsPeriodMinutes,
    ) {}
}
