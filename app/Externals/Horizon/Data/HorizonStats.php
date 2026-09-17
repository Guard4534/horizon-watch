<?php

namespace App\Externals\Horizon\Data;

final readonly class HorizonStats
{
    /**
     * @param  string  $status  running, paused or inactive
     * @param  int  $failedJobs  counted over the last $failedJobsPeriodMinutes
     * @param  array<string, int>  $wait  seconds, keyed by "connection:queue"
     * @param  int  $failedJobsPeriodMinutes  Horizon's own failed-jobs window (its trim setting), not always a day
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
