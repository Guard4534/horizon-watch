<?php

namespace App\Monitoring;

use App\Alerts\RuleSet;
use App\Enums\AlertRuleMetric;
use App\Enums\EnvironmentStatus;
use App\Externals\Horizon\Data\HorizonFailedJob;
use App\Externals\Horizon\Data\HorizonMaster;
use App\Externals\Horizon\Data\HorizonPendingJob;
use App\Externals\Horizon\Data\HorizonQueueLoad;
use App\Externals\Horizon\HorizonReading;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\Config;

final class StatusEvaluator
{
    public function __construct(
        #[Config('horizon-watch.readings.failed_rate_minutes')]
        private readonly int $failedRateMinutes,
    ) {}

    public function evaluate(HorizonReading $reading, RuleSet $rules, ?CarbonImmutable $at = null): EvaluatedStatus
    {
        $at ??= CarbonImmutable::now();
        $failedLastHour = $this->failedLastHour($reading->failedJobs ?? [], $at);

        if ($reading->stats->status === 'inactive' || $reading->masters === []) {
            return new EvaluatedStatus(
                EnvironmentStatus::Inactive,
                $this->enabled([AlertRuleMetric::HorizonMasterInactive], $rules),
                $failedLastHour,
            );
        }

        $breaches = array_values(array_filter(
            AlertRuleMetric::cases(),
            fn (AlertRuleMetric $metric) => $rules->for($metric)->enabled
                && $this->breached($metric, $reading, $failedLastHour, $rules->for($metric)->threshold, $at),
        ));

        if ($reading->stats->status === 'paused' || $this->everyMasterPaused($reading->masters)) {
            return new EvaluatedStatus(
                EnvironmentStatus::Paused,
                [...$this->enabled([AlertRuleMetric::HorizonPaused], $rules), ...$breaches],
                $failedLastHour,
            );
        }

        return new EvaluatedStatus(
            $breaches === [] ? EnvironmentStatus::Active : EnvironmentStatus::Degraded,
            $breaches,
            $failedLastHour,
        );
    }

    /**
     * @param  list<AlertRuleMetric>  $metrics
     * @return list<AlertRuleMetric>
     */
    private function enabled(array $metrics, RuleSet $rules): array
    {
        return array_values(array_filter($metrics, fn (AlertRuleMetric $metric) => $rules->for($metric)->enabled));
    }

    /**
     * @param  list<HorizonFailedJob>  $jobs
     */
    public function failedLastHour(array $jobs, ?CarbonImmutable $at = null): int
    {
        $since = ($at ?? CarbonImmutable::now())->subMinutes($this->failedRateMinutes);

        return count(array_filter($jobs, fn (HorizonFailedJob $job) => $job->failedAt->gte($since)));
    }

    public function failed(): EvaluatedStatus
    {
        return new EvaluatedStatus(EnvironmentStatus::Unreachable, [AlertRuleMetric::EndpointUnreachable]);
    }

    /**
     * @param  list<HorizonMaster>  $masters
     */
    private function everyMasterPaused(array $masters): bool
    {
        foreach ($masters as $master) {
            if ($master->status !== 'paused') {
                return false;
            }
        }

        return true;
    }

    private function breached(AlertRuleMetric $metric, HorizonReading $reading, int $failedLastHour, float $threshold, CarbonImmutable $at): bool
    {
        $workload = $reading->workload;

        return match ($metric) {
            AlertRuleMetric::QueuePending => array_sum(array_map(fn (HorizonQueueLoad $queue) => $queue->length, $workload)) > $threshold,
            AlertRuleMetric::QueueMaxWait => max([0, ...array_map(fn (HorizonQueueLoad $queue) => $queue->wait, $workload)]) > $threshold,
            AlertRuleMetric::JobsFailedPerHour => $failedLastHour > $threshold,
            AlertRuleMetric::WorkersMissing => count(array_filter(
                $workload,
                fn (HorizonQueueLoad $queue) => $queue->processes === 0 && $queue->length > 0,
            )) >= $threshold,
            AlertRuleMetric::JobRuntime => $this->hasLongReservedJob($reading->pendingJobs ?? [], $threshold, $at),
            AlertRuleMetric::HorizonMasterInactive, AlertRuleMetric::EndpointUnreachable, AlertRuleMetric::HorizonPaused => false,
        };
    }

    /**
     * @param  list<HorizonPendingJob>  $jobs
     */
    private function hasLongReservedJob(array $jobs, float $thresholdSeconds, CarbonImmutable $at): bool
    {
        foreach ($jobs as $job) {
            if ($job->status === 'reserved'
                && $job->reservedAt !== null
                && $job->reservedAt->diffInSeconds($at) > $thresholdSeconds) {
                return true;
            }
        }

        return false;
    }
}
