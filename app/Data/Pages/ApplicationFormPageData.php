<?php

namespace App\Data\Pages;

use App\Data\Applications\ApplicationFormData;
use Spatie\LaravelData\Data;

class ApplicationFormPageData extends Data
{
    public function __construct(
        public ?ApplicationFormData $application,
        /**
         * @var array<int, array{value: string, label: string}>
         */
        public array $colors,
        public ?string $slug = null,
    ) {}
}
