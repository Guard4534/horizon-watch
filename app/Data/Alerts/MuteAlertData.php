<?php

namespace App\Data\Alerts;

use App\Enums\MuteDuration;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;

class MuteAlertData extends Data
{
    public function __construct(
        public MuteDuration $duration,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'duration' => ['required', 'string', Rule::enum(MuteDuration::class)],
        ];
    }
}
