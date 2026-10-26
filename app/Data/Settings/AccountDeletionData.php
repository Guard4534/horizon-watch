<?php

namespace App\Data\Settings;

use Spatie\LaravelData\Data;

class AccountDeletionData extends Data
{
    public function __construct(
        /** @var array<int, string> */
        public array $sharedOrganizations,
        /** @var array<int, string> */
        public array $soleOrganizations,
    ) {}
}
