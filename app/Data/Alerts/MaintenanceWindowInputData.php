<?php

namespace App\Data\Alerts;

use App\Enums\MaintenanceScope;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;

class MaintenanceWindowInputData extends Data
{
    public function __construct(
        public MaintenanceScope $scope,
        public ?string $target,
        public string $startsAt,
        public string $endsAt,
        public ?string $reason,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'scope' => ['required', 'string', Rule::enum(MaintenanceScope::class)],
            'target' => ['required_unless:scope,'.MaintenanceScope::Organization->value, 'nullable', 'string', 'max:255'],
            'startsAt' => ['required', 'date'],
            'endsAt' => ['required', 'date', 'after:startsAt', 'after:now'],
            'reason' => ['present', 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'endsAt.after' => __('The window must end after it starts, and in the future.'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return [
            'scope' => __('scope'),
            'target' => __('target'),
            'startsAt' => __('start'),
            'endsAt' => __('end'),
            'reason' => __('reason'),
        ];
    }
}
