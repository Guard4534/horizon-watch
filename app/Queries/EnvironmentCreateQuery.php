<?php

namespace App\Queries;

use App\Data\Applications\ApplicationFormData;
use App\Data\Pages\EnvironmentFormPageData;
use App\Enums\EnvironmentColor;
use App\Models\Application;

class EnvironmentCreateQuery
{
    public function handle(Application $application): EnvironmentFormPageData
    {
        return new EnvironmentFormPageData(
            environment: null,
            application: ApplicationFormData::from($application),
            colors: EnvironmentColor::options(),
            hasPassword: false,
            applicationSlug: $application->slug,
        );
    }
}
