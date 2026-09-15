<?php

namespace App\Data\Monitoring;

use App\Enums\AlertRuleMetric;
use App\Enums\AlertSeverity;
use App\Enums\AlertState;
use App\Enums\EnvironmentColor;
use App\Enums\EnvironmentStatus;
use App\Enums\NotificationChannel;
use Spatie\LaravelData\Data;

class AlertData extends Data
{
    public function __construct(
        public string $id,
        public AlertState $state,
        public AlertSeverity $severity,
        public AlertRuleMetric $metric,
        public float $threshold,
        public string $unit,
        public string $environmentId,
        public string $applicationName,
        public string $environmentName,
        public EnvironmentColor $color,
        public EnvironmentStatus $environmentStatus,
        public int $nodeCount,
        public int $pending,
        public int $maxWaitSeconds,
        public int $minutesAgo,
        /** @var array<int, NotificationChannel> */
        public array $channels,
    ) {}
}
