<?php

namespace App\Enums;

enum AlertRuleMetric: string
{
    case HorizonMasterInactive = 'horizon.master_inactive';
    case EndpointUnreachable = 'endpoint.unreachable';
    case QueuePending = 'queue.pending';
    case QueueMaxWait = 'queue.max_wait';
    case JobRuntime = 'job.runtime';
    case JobsFailedPerHour = 'jobs.failed_per_hour';
    case WorkersMissing = 'workers.missing';

    public function unit(): string
    {
        return match ($this) {
            self::HorizonMasterInactive, self::EndpointUnreachable => 'min',
            self::QueuePending, self::JobsFailedPerHour => 'job',
            self::QueueMaxWait, self::JobRuntime => 's',
            self::WorkersMissing => '',
        };
    }

    public function defaultThreshold(): float
    {
        return match ($this) {
            self::HorizonMasterInactive => 5,
            self::EndpointUnreachable => 2,
            self::QueuePending => 2000,
            self::QueueMaxWait => 60,
            self::JobRuntime => 120,
            self::JobsFailedPerHour => 20,
            self::WorkersMissing => 4,
        };
    }

    public function defaultSeverity(): AlertSeverity
    {
        return match ($this) {
            self::HorizonMasterInactive, self::EndpointUnreachable => AlertSeverity::Critical,
            default => AlertSeverity::Warning,
        };
    }

    public function notifiesByEmailByDefault(): bool
    {
        return ! in_array($this, [self::JobRuntime, self::WorkersMissing], true);
    }
}
