<?php

namespace App\Data\Monitoring;

use App\Monitoring\HealthReport;
use Spatie\LaravelData\Data;

class PanelHealthData extends Data
{
    public function __construct(
        public bool $healthy,
        public bool $schedulerLate,
        public ?int $schedulerSecondsAgo,
        public bool $backlogLate,
        public ?int $oldestWaitSeconds,
    ) {}

    public static function fromReport(HealthReport $report): self
    {
        return new self(
            healthy: $report->healthy(),
            schedulerLate: $report->schedulerLate(),
            schedulerSecondsAgo: $report->schedulerSecondsAgo,
            backlogLate: $report->backlogLate(),
            oldestWaitSeconds: $report->oldestWaitSeconds(),
        );
    }
}
