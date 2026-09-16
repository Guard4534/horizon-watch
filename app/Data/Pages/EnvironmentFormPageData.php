<?php

namespace App\Data\Pages;

use App\Data\Applications\ApplicationFormData;
use App\Data\Applications\EnvironmentSummaryData;
use Spatie\LaravelData\Data;

class EnvironmentFormPageData extends Data
{
    public function __construct(
        // Null on create, filled on edit. EnvironmentSummaryData has no
        // password property, so this can never carry the credential.
        public ?EnvironmentSummaryData $environment,
        public ApplicationFormData $application,
        /** @var array<int, array{value: string, label: string}> */
        public array $colors,
        // "Credentials configured" without revealing them.
        public bool $hasPassword,
    ) {}
}
