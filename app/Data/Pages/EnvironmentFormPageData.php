<?php

namespace App\Data\Pages;

use App\Data\Applications\ApplicationFormData;
use App\Data\Applications\EnvironmentSummaryData;
use Spatie\LaravelData\Data;

class EnvironmentFormPageData extends Data
{
    public function __construct(
        public ?EnvironmentSummaryData $environment,
        public ApplicationFormData $application,
        /** @var array<int, array{value: string, label: string}> */
        public array $colors,
        public bool $hasPassword,
        public string $applicationSlug,
        public ?string $slug = null,
    ) {}
}
