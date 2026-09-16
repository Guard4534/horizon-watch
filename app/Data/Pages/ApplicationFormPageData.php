<?php

namespace App\Data\Pages;

use App\Data\Applications\ApplicationFormData;
use Spatie\LaravelData\Data;

class ApplicationFormPageData extends Data
{
    public function __construct(
        // Null on create, filled on edit.
        public ?ApplicationFormData $application,
    ) {}
}
