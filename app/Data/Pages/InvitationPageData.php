<?php

namespace App\Data\Pages;

use Spatie\LaravelData\Data;

class InvitationPageData extends Data
{
    /**
     * @param  array<int, string>  $visibleEnvironmentNames
     */
    public function __construct(
        public string $code,
        public ?string $organizationName,
        public ?string $roleLabel,
        public ?string $visibilityLabel,
        public ?string $email,
        public array $visibleEnvironmentNames,
        public string $state,
        public bool $authenticated,
    ) {}
}
