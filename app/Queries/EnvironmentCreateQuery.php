<?php

namespace App\Queries;

use App\Data\Applications\ApplicationFormData;
use App\Data\Applications\EnvironmentFormData;
use App\Data\Pages\EnvironmentFormPageData;
use App\Enums\EnvironmentType;
use App\Models\Application;
use App\Support\HorizonUrl;

class EnvironmentCreateQuery
{
    public function handle(Application $application): EnvironmentFormPageData
    {
        $primary = Application::primaryEnvironment($application->environments()->get());

        return new EnvironmentFormPageData(
            environment: null,
            application: ApplicationFormData::from($application),
            types: EnvironmentType::options(),
            hasPassword: false,
            applicationSlug: $application->slug,
            domain: $primary === null ? null : HorizonUrl::host($primary->horizon_url),
            baseHorizonUrl: $primary === null ? null : HorizonUrl::normalize(EnvironmentFormData::withoutUserinfo($primary->horizon_url)),
            baseEnvironmentName: $primary?->name,
        );
    }
}
