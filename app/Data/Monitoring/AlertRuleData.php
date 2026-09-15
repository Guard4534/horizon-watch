<?php

namespace App\Data\Monitoring;

use App\Enums\AlertRuleMetric;
use App\Enums\AlertSeverity;
use App\Enums\RuleOrigin;
use Spatie\LaravelData\Data;

class AlertRuleData extends Data
{
    public function __construct(
        public AlertRuleMetric $metric,
        public float $threshold,
        public string $unit,
        public ?AlertSeverity $severity,
        public bool $notifyByEmail,
        public RuleOrigin $origin,
    ) {}
}
