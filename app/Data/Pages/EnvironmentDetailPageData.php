<?php

namespace App\Data\Pages;

use App\Data\Monitoring\AlertData;
use App\Data\Monitoring\AlertRuleData;
use App\Data\Monitoring\EnvironmentData;
use App\Data\Monitoring\FailedJobData;
use App\Data\Monitoring\LongRunningJobData;
use App\Data\Monitoring\NodeData;
use App\Data\Monitoring\QueueData;
use Spatie\LaravelData\Data;

class EnvironmentDetailPageData extends Data
{
    public function __construct(
        public EnvironmentData $environment,
        /** @var array<int, AlertData> */
        public array $alerts,
        /** @var array<int, NodeData> */
        public array $nodes,
        /** @var array<int, QueueData> */
        public array $queues,
        /** @var array<int, FailedJobData> */
        public array $failedJobs,
        /** @var array<int, LongRunningJobData> */
        public array $longRunningJobs,
        /** @var array<int, AlertRuleData> */
        public array $rules,
        public bool $canTestConnection,
        public bool $canSilence,
        /** @var array<int, int> */
        public array $silenceChoices,
    ) {}
}
