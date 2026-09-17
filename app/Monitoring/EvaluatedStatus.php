<?php

namespace App\Monitoring;

use App\Enums\AlertRuleMetric;
use App\Enums\EnvironmentStatus;

final readonly class EvaluatedStatus
{
    /**
     * @param  list<AlertRuleMetric>  $breaches
     */
    public function __construct(
        public EnvironmentStatus $status,
        public array $breaches,
        public int $failedLastHour = 0,
    ) {}
}
