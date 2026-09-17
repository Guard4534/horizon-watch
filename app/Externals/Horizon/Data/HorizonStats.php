<?php

namespace App\Externals\Horizon\Data;

final readonly class HorizonStats
{
    /**
     * @param  string  $status  running, paused or inactive
     * @param  int  $failedJobs  Horizon counts them over the last 1440 minutes
     * @param  array<string, int>  $wait  seconds, keyed by "connection:queue"
     */
    public function __construct(
        public string $status,
        public int $jobsPerMinute,
        public int $failedJobs,
        public int $processes,
        public int $pausedMasters,
        public array $wait,
    ) {}
}
