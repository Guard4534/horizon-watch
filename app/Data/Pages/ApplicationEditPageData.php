<?php

namespace App\Data\Pages;

use App\Data\Applications\ApplicationFormData;
use Spatie\LaravelData\Data;

class ApplicationEditPageData extends Data
{
    public function __construct(
        public ApplicationFormData $application,
        public string $slug,
    ) {}
}
