<?php

namespace App\Data\Pages;

use App\Data\Applications\ApplicationFormData;
use App\Data\Applications\EnvironmentSummaryData;
use App\Data\Applications\PollIntervalData;
use Spatie\LaravelData\Data;

class EnvironmentFormPageData extends Data
{
    public function __construct(
        public ?EnvironmentSummaryData $environment,
        public ApplicationFormData $application,
        /** @var array<int, array{value: string, label: string}> */
        public array $types,
        public bool $hasPassword,
        public string $applicationSlug,
        public PollIntervalData $pollInterval,
        public ?string $slug = null,
        public ?string $domain = null,
        public ?string $baseHorizonUrl = null,
        public ?string $baseEnvironmentName = null,
    ) {}
}
