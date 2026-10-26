<?php

namespace App\Enums;

use App\Externals\Horizon\HorizonReader;

enum AlertRuleMetric: string
{
    case HorizonMasterInactive = 'horizon.master_inactive';
    case EndpointUnreachable = 'endpoint.unreachable';
    case HorizonPaused = 'horizon.paused';
    case QueuePending = 'queue.pending';
    case QueueMaxWait = 'queue.max_wait';
    case JobRuntime = 'job.runtime';
    case JobsFailedPerHour = 'jobs.failed_per_hour';
    case WorkersMissing = 'workers.missing';

    public function unit(): string
    {
        return match ($this) {
            self::HorizonMasterInactive, self::EndpointUnreachable, self::HorizonPaused => 'min',
            self::QueuePending, self::JobsFailedPerHour => 'job',
            self::QueueMaxWait, self::JobRuntime => 's',
            self::WorkersMissing => '',
        };
    }

    public function comparator(): string
    {
        return $this->isStateRule() || $this === self::WorkersMissing ? '≥' : '>';
    }

    public function defaultThreshold(): float
    {
        return match ($this) {
            self::HorizonMasterInactive => 5,
            self::EndpointUnreachable => 2,
            self::HorizonPaused => 15,
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
        return ! in_array($this, [self::JobRuntime, self::WorkersMissing, self::HorizonPaused], true);
    }

    public function minimumThreshold(): float
    {
        return 1;
    }

    public function maximumThreshold(): float
    {
        return match ($this) {
            self::HorizonMasterInactive, self::EndpointUnreachable, self::HorizonPaused => 1440,
            self::JobsFailedPerHour => (float) max(1, min(
                config()->integer('horizon-watch.horizon.max_jobs'),
                config()->integer('horizon-watch.horizon.max_job_pages') * HorizonReader::JOBS_PER_PAGE,
            ) - 1),
            self::QueuePending => 2147483647,
            self::QueueMaxWait, self::JobRuntime => 86400,
            self::WorkersMissing => 1000,
        };
    }

    public function isStateRule(): bool
    {
        return in_array($this, [self::HorizonMasterInactive, self::EndpointUnreachable, self::HorizonPaused], true);
    }
}
