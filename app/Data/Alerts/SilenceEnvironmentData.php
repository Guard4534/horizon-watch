<?php

namespace App\Data\Alerts;

use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;

class SilenceEnvironmentData extends Data
{
    public function __construct(
        public int $minutes,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'minutes' => ['required', 'integer', Rule::in(config()->array('horizon-watch.alerts.silence_minutes'))],
        ];
    }
}
