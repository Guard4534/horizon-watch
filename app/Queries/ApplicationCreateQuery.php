<?php

namespace App\Queries;

use App\Data\Pages\ApplicationFormPageData;

class ApplicationCreateQuery
{
    public function handle(): ApplicationFormPageData
    {
        return new ApplicationFormPageData(application: null);
    }
}
