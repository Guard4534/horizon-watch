<?php

namespace App\Data\Alerts;

use App\Enums\AlertRuleMetric;
use App\Enums\AlertSeverity;
use App\Models\AlertRule;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

class AlertRuleInputData extends Data
{
    public function __construct(
        public AlertRuleMetric $metric,
        public ?int $threshold = null,
        public ?AlertSeverity $severity = null,
        public ?bool $notifyByEmail = null,
        public ?bool $enabled = null,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(ValidationContext $context): array
    {
        $presence = self::organizationScope() ? 'required' : 'nullable';
        $metric = is_array($context->payload) && is_string($context->payload['metric'] ?? null)
            ? AlertRuleMetric::tryFrom($context->payload['metric'])
            : null;

        return [
            'metric' => ['required', Rule::enum(AlertRuleMetric::class)],
            'threshold' => [
                $presence,
                'present',
                'integer',
                ...($metric === null ? [] : [
                    'min:'.(int) $metric->minimumThreshold(),
                    'max:'.(int) $metric->maximumThreshold(),
                ]),
            ],
            'severity' => [$presence, 'present', new Enum(AlertSeverity::class)],
            'notifyByEmail' => [$presence, 'present', 'boolean'],
            'enabled' => [$presence, 'present', 'boolean'],
        ];
    }

    public function isEmpty(): bool
    {
        return $this->threshold === null
            && $this->severity === null
            && $this->notifyByEmail === null
            && $this->enabled === null;
    }

    private static function organizationScope(): bool
    {
        $scope = request()->route()?->parameter('scope');

        return is_string($scope) && Str::lower($scope) === AlertRule::ORGANIZATION;
    }
}
