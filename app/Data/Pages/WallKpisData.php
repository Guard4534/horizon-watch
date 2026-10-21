<?php

namespace App\Data\Pages;

use App\Enums\AlertSeverity;
use Spatie\LaravelData\Data;

class WallKpisData extends Data
{
    public function __construct(
        public int $environmentsHealthy,
        public int $environmentsJudged,
        public int $environmentsNeedingAttention,
        public int $openAlerts,
        public ?AlertSeverity $openAlertSeverity,
        public int $pendingTotal,
        public int $failedLastHour,
        public bool $failedLastHourCapped,
        public ?AlertSeverity $failedSeverity,
        public int $failedInWindow,
        public ?int $failedWindowMinutes,
    ) {}
}
