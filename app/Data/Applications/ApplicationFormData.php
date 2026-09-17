<?php

namespace App\Data\Applications;

use App\Rules\HostWithoutSchemeOrCredentials;
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
            'host' => ['required', 'string', 'max:255', new HostWithoutSchemeOrCredentials],
        ];
    }
}
