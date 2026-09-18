<?php

namespace App\Alerts;

use App\Enums\AlertRuleMetric;

final readonly class RuleSet
{
    /**
     * @param  array<string, EffectiveRule>  $rules
     */
    public function __construct(public array $rules) {}

    public function for(AlertRuleMetric $metric): EffectiveRule
    {
        return $this->rules[$metric->value] ?? EffectiveRule::default($metric);
    }
}
