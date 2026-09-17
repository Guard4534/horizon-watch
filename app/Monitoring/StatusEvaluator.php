<?php

namespace App\Monitoring;

use App\Enums\AlertRuleMetric;
use App\Enums\EnvironmentStatus;
use App\Enums\ReadingError;
use App\Externals\Horizon\Data\HorizonMaster;
use App\Externals\Horizon\Data\HorizonPendingJob;
use App\Externals\Horizon\Data\HorizonQueueLoad;
use App\Externals\Horizon\HorizonReading;
use Carbon\CarbonImmutable;

/**
 * Pure on purpose: no container, no database, no translator, so it runs in
 * tests/Unit. The clock is Carbon's own, which Laravel's travel helpers
 * also move. Thresholds are the defaults until phase 4 makes them editable.
 */
final class StatusEvaluator
{
    public function evaluate(HorizonReading $reading): EvaluatedStatus
    {
        if ($reading->stats->status === 'inactive' || $reading->masters === []) {
            return new EvaluatedStatus(EnvironmentStatus::Inactive, [AlertRuleMetric::HorizonMasterInactive]);
        }

        if ($reading->stats->status === 'paused' || $this->everyMasterPaused($reading->masters)) {
            return new EvaluatedStatus(EnvironmentStatus::Paused, []);
        }

        $breaches = array_values(array_filter(
            AlertRuleMetric::cases(),
            fn (AlertRuleMetric $metric) => $this->breached($metric, $reading),
        ));

        return new EvaluatedStatus(
            $breaches === [] ? EnvironmentStatus::Active : EnvironmentStatus::Degraded,
            $breaches,
        );
    }

    public function failed(ReadingError $error): EvaluatedStatus
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

    private function breached(AlertRuleMetric $metric, HorizonReading $reading): bool
    {
        $threshold = $metric->defaultThreshold();
        $workload = $reading->workload;

        return match ($metric) {
            AlertRuleMetric::QueuePending => array_sum(array_map(fn (HorizonQueueLoad $queue) => $queue->length, $workload)) > $threshold,
            AlertRuleMetric::QueueMaxWait => max([0, ...array_map(fn (HorizonQueueLoad $queue) => $queue->wait, $workload)]) > $threshold,
            // Horizon counts failed jobs over 1440 minutes.
            AlertRuleMetric::JobsFailedPerHour => $reading->stats->failedJobs / 24 > $threshold,
            AlertRuleMetric::WorkersMissing => count(array_filter(
                $workload,
                fn (HorizonQueueLoad $queue) => $queue->processes === 0 && $queue->length > 0,
            )) >= $threshold,
            AlertRuleMetric::JobRuntime => $this->hasLongReservedJob($reading->pendingJobs ?? [], $threshold),
            // Not thresholds: they come from the status branches above.
            AlertRuleMetric::HorizonMasterInactive, AlertRuleMetric::EndpointUnreachable => false,
        };
    }

    /**
     * @param  list<HorizonPendingJob>  $jobs
     */
    private function hasLongReservedJob(array $jobs, float $thresholdSeconds): bool
    {
        $now = CarbonImmutable::now();

        foreach ($jobs as $job) {
            if ($job->status === 'reserved'
                && $job->reservedAt !== null
                && $job->reservedAt->diffInSeconds($now) > $thresholdSeconds) {
                return true;
            }
        }

        return false;
    }
}
