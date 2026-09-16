<?php

namespace App\Queries;

use App\Data\Pages\ApplicationFormPageData;
use App\Enums\EnvironmentColor;

class ApplicationCreateQuery
{
    public function handle(): ApplicationFormPageData
    {
        return new ApplicationFormPageData(
            application: null,
            colors: EnvironmentColor::options(),
        );
    }
}
