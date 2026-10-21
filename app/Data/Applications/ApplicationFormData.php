<?php

namespace App\Data\Applications;

use Spatie\LaravelData\Data;

class ApplicationFormData extends Data
{
    public function __construct(
        public string $name,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
