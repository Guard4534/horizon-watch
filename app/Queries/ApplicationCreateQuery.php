<?php

namespace App\Queries;

use App\Data\Pages\ApplicationFormPageData;
use App\Enums\EnvironmentType;

class ApplicationCreateQuery
{
    public function handle(): ApplicationFormPageData
    {
        return new ApplicationFormPageData(
            application: null,
            types: EnvironmentType::options(),
            maxEnvironments: config()->integer('horizon-watch.limits.wizard_environments'),
        );
    }
}
