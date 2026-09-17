<?php

namespace App\Externals\Horizon;

use App\Externals\Horizon\Data\HorizonFailedJob;
use App\Externals\Horizon\Data\HorizonMaster;
use App\Externals\Horizon\Data\HorizonPendingJob;
use App\Externals\Horizon\Data\HorizonQueueLoad;
use App\Externals\Horizon\Data\HorizonStats;

/**
 * A null job list means the secondary call failed: the poller keeps the
 * previous section rather than showing an empty one.
 */
final readonly class HorizonReading
{
    /**
     * @param  list<HorizonMaster>  $masters
     * @param  list<HorizonQueueLoad>  $workload
     * @param  list<HorizonFailedJob>|null  $failedJobs
     * @param  list<HorizonPendingJob>|null  $pendingJobs
     * @param  array<string, float>  $queueRuntimes  seconds, keyed by queue name; a queue without snapshots is absent
     */
    public function __construct(
        public HorizonStats $stats,
        public array $masters,
        public array $workload,
        public ?array $failedJobs,
        public ?array $pendingJobs,
        public array $queueRuntimes,
        public int $latencyMs,
    ) {}
}
