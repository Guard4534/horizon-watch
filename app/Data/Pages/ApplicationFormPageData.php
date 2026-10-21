<?php

namespace App\Data\Pages;

use App\Data\Applications\ApplicationFormData;
use App\Data\Applications\PollIntervalData;
use Spatie\LaravelData\Data;

class ApplicationFormPageData extends Data
{
    public function __construct(
        public ?ApplicationFormData $application,
        /**
         * @var array<int, array{value: string, label: string}>
         */
        public array $types,
        public PollIntervalData $pollInterval,
        public ?string $slug = null,
        public ?int $maxEnvironments = null,
    ) {}
}
