<?php

namespace App\Queries;

use App\Data\Applications\ApplicationFormData;
use App\Data\Pages\ApplicationFormPageData;
use App\Enums\EnvironmentColor;
use App\Models\Application;

class ApplicationEditQuery
{
    public function handle(Application $application): ApplicationFormPageData
    {
        return new ApplicationFormPageData(
            application: ApplicationFormData::from($application),
            colors: EnvironmentColor::options(),
            slug: $application->slug,
        );
    }
}
