<?php

namespace App\Data\Monitoring;

use App\Alerts\EffectiveRule;
use App\Enums\AlertRuleMetric;
use App\Enums\AlertSeverity;
use App\Enums\RuleOrigin;
use Spatie\LaravelData\Data;

class AlertRuleData extends Data
{
    public function __construct(
        public AlertRuleMetric $metric,
        public string $unit,
        public float $threshold,
        public AlertSeverity $severity,
        public bool $notifyByEmail,
        public bool $enabled,
        public ?float $overrideThreshold,
        public ?AlertSeverity $overrideSeverity,
        public ?bool $overrideNotifyByEmail,
        public ?bool $overrideEnabled,
        public float $minimum,
        public float $maximum,
        public RuleOrigin $origin,
    ) {}

    public static function fromEffective(EffectiveRule $rule): self
    {
        $overridden = fn (RuleOrigin $origin): bool => $origin === RuleOrigin::Override;

        return new self(
            metric: $rule->metric,
            unit: $rule->metric->unit(),
            threshold: $rule->threshold,
            severity: $rule->severity,
            notifyByEmail: $rule->notifyByEmail,
            enabled: $rule->enabled,
            overrideThreshold: $overridden($rule->thresholdOrigin) ? $rule->threshold : null,
            overrideSeverity: $overridden($rule->severityOrigin) ? $rule->severity : null,
            overrideNotifyByEmail: $overridden($rule->notifyOrigin) ? $rule->notifyByEmail : null,
            overrideEnabled: $overridden($rule->enabledOrigin) ? $rule->enabled : null,
            minimum: $rule->metric->minimumThreshold(),
            maximum: $rule->metric->maximumThreshold(),
            origin: array_any(
                [$rule->thresholdOrigin, $rule->severityOrigin, $rule->notifyOrigin, $rule->enabledOrigin],
                $overridden,
            ) ? RuleOrigin::Override : RuleOrigin::Organization,
        );
    }
}
