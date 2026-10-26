<?php

namespace App\Alerts;

use App\Enums\AlertRuleMetric;
use App\Enums\AlertSeverity;
use App\Enums\RuleOrigin;

final readonly class EffectiveRule
{
    public function __construct(
        public AlertRuleMetric $metric,
        public float $threshold,
        public AlertSeverity $severity,
        public bool $notifyByEmail,
        public bool $enabled,
        public int $forMinutes,
        public RuleOrigin $thresholdOrigin,
        public RuleOrigin $severityOrigin,
        public RuleOrigin $notifyOrigin,
        public RuleOrigin $enabledOrigin,
        public RuleOrigin $forMinutesOrigin,
    ) {}

    public static function default(AlertRuleMetric $metric): self
    {
        return new self(
            metric: $metric,
            threshold: $metric->defaultThreshold(),
            severity: $metric->defaultSeverity(),
            notifyByEmail: $metric->notifiesByEmailByDefault(),
            enabled: true,
            forMinutes: 0,
            thresholdOrigin: RuleOrigin::Organization,
            severityOrigin: RuleOrigin::Organization,
            notifyOrigin: RuleOrigin::Organization,
            enabledOrigin: RuleOrigin::Organization,
            forMinutesOrigin: RuleOrigin::Organization,
        );
    }
}
