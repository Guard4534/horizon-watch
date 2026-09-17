<?php

namespace App\Data\Pages;

use App\Data\Monitoring\AlertData;
use App\Data\Monitoring\AlertRuleData;
use App\Data\Monitoring\EnvironmentData;
use App\Data\Monitoring\FailedJobData;
use App\Data\Monitoring\LongRunningJobData;
use App\Data\Monitoring\NodeData;
use App\Data\Monitoring\QueueData;
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
        /** @var array<int, AlertRuleData> */
        public array $rules,
        public int $overrideCount,
        // The effective rule scope backing $rules: the environment's own name
        // when it has one, otherwise 'organization'. Computed here so the
        // front end stops re-deriving it from overrideCount (a non-zero
        // override count and a real per-environment scope are not the same
        // thing).
        public string $scope,
        // The saved-address probe (environments.test-connection without a
        // body), which a member may run on what they watch.
        public bool $canTestConnection,
        // The thresholds that open anomalies, keyed by metric. Not $rules:
        // their overrides are invented until phase 4 makes them real, and
        // the page's colours and notes must agree with the status.
        /** @var array<string, float> */
        public array $thresholds,
    ) {}
}
