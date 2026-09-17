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
        // The organization defaults, the values the evaluator uses. The
        // per-environment overrides are invented examples until phase 4 and
        // stay on the alert-rules page: here they would contradict the
        // status next to them.
        /** @var array<int, AlertRuleData> */
        public array $rules,
        // The saved-address probe (environments.test-connection without a
        // body), which a member may run on what they watch.
        public bool $canTestConnection,
        // $rules keyed by metric, for the page's colours and notes.
        /** @var array<string, float> */
        public array $thresholds,
    ) {}
}
