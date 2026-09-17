<?php

namespace App\Externals\Horizon\Data;

final readonly class HorizonStats
{
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
