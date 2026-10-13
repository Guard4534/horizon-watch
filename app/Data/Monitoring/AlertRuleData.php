<?php

namespace App\Data\Monitoring;

use App\Alerts\EffectiveRule;
use App\Enums\AlertRuleMetric;
use App\Enums\AlertSeverity;
use App\Enums\RuleOrigin;
use App\Models\AlertRule;
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
        public ?int $forMinutes,
        public ?float $overrideThreshold,
        public ?AlertSeverity $overrideSeverity,
        public ?bool $overrideNotifyByEmail,
        public ?bool $overrideEnabled,
        public ?int $overrideForMinutes,
        public float $minimum,
        public float $maximum,
        public int $maximumForMinutes,
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
            forMinutes: $rule->metric->isStateRule() ? null : $rule->forMinutes,
            overrideThreshold: $overridden($rule->thresholdOrigin) ? $rule->threshold : null,
            overrideSeverity: $overridden($rule->severityOrigin) ? $rule->severity : null,
            overrideNotifyByEmail: $overridden($rule->notifyOrigin) ? $rule->notifyByEmail : null,
            overrideEnabled: $overridden($rule->enabledOrigin) ? $rule->enabled : null,
            overrideForMinutes: $overridden($rule->forMinutesOrigin) ? $rule->forMinutes : null,
            minimum: $rule->metric->minimumThreshold(),
            maximum: $rule->metric->maximumThreshold(),
            maximumForMinutes: AlertRule::MAX_FOR_MINUTES,
            origin: array_any(
                [$rule->thresholdOrigin, $rule->severityOrigin, $rule->notifyOrigin, $rule->enabledOrigin, $rule->forMinutesOrigin],
                $overridden,
            ) ? RuleOrigin::Override : RuleOrigin::Organization,
        );
    }
}
