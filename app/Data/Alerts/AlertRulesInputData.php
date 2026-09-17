<?php

namespace App\Data\Alerts;

use App\Enums\AlertRuleMetric;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

class AlertRulesInputData extends Data
{
    public function __construct(
        /** @var array<int, AlertRuleInputData> */
        #[DataCollectionOf(AlertRuleInputData::class)]
        public array $rules,
    ) {}

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return [
            'rules' => ['required', 'array', 'list', 'max:'.count(AlertRuleMetric::cases())],
            'rules.*.metric' => ['distinct'],
        ];
    }
}
