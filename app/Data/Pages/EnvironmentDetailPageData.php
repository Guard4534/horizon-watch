<?php

namespace App\Data\Pages;

use App\Data\Monitoring\AlertData;
use App\Data\Monitoring\AlertRuleData;
use App\Data\Monitoring\EnvironmentData;
use App\Data\Monitoring\FailedJobData;
use App\Data\Monitoring\LongRunningJobData;
use App\Data\Monitoring\NodeData;
use App\Data\Monitoring\QueueData;
use App\Data\Monitoring\SeriesGridData;
use App\Enums\SeriesRange;
use Spatie\LaravelData\Data;

class EnvironmentDetailPageData extends Data
{
    public function __construct(
        public EnvironmentData $environment,
        public ?AlertData $openAlert,
        /** @var array<int, NodeData> */
        public array $nodes,
        /** @var array<int, QueueData> */
        public array $queues,
        /** @var array<int, FailedJobData> */
        public array $failedJobs,
        /** @var array<int, LongRunningJobData> */
        public array $longRunningJobs,
        public SeriesRange $range,
        /** @var array<int, int> */
        public array $throughput,
        /** @var array<int, int> */
        public array $maxWait,
        public SeriesGridData $grid,
        /** @var array<int, AlertRuleData> */
        public array $rules,
        public bool $canTestConnection,
    ) {}
}
