<?php

namespace App\Data\Applications;

use Spatie\LaravelData\Data;

class ApplicationFormData extends Data
{
    public function __construct(
        public string $name,
        public string $host,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // No scheme: the panel builds each environment's Horizon URL on
            // its own, so a bare host keeps the two concerns unambiguous.
            'host' => ['required', 'string', 'max:255', 'regex:/^(?!https?:\/\/).+$/i'],
        ];
    }
}
