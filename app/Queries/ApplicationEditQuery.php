<?php

namespace App\Queries;

use App\Data\Applications\ApplicationFormData;
use App\Data\Pages\ApplicationEditPageData;
use App\Models\Application;

class ApplicationEditQuery
{
    public function handle(Application $application): ApplicationEditPageData
    {
        return new ApplicationEditPageData(
            application: ApplicationFormData::from($application),
            slug: $application->slug,
        );
    }
}
