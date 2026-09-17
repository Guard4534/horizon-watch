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
            // No scheme: the panel builds each environment's Horizon URL on
            // its own, so a bare host keeps the two concerns unambiguous. No
            // credentials either: the host is shown to everyone and the
            // wizard copies it into the suggested URLs.
            'host' => ['required', 'string', 'max:255', new HostWithoutSchemeOrCredentials],
        ];
    }
}
