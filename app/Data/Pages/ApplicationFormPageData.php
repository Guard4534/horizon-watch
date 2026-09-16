<?php

namespace App\Data\Pages;

use App\Data\Applications\ApplicationFormData;
use Spatie\LaravelData\Data;

class ApplicationFormPageData extends Data
{
    public function __construct(
        // Null on create, filled on edit.
        public ?ApplicationFormData $application,
        /**
         * The fixed environment palette (EnvironmentColor::options()). The
         * create page is the wizard, which configures the application's
         * first environments in the same request, so it needs the same
         * palette EnvironmentFormPageData hands to the environment pages.
         *
         * @var array<int, array{value: string, label: string}>
         */
        public array $colors,
        // The route key the edit page submits to (applications.update and
        // applications.destroy). Null on create: there is no slug before
        // AddApplication has run. ApplicationFormData deliberately stays
        // exactly the writable form, so it carries no identifier.
        public ?string $slug = null,
    ) {}
}
