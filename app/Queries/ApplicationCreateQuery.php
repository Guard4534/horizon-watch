<?php

namespace App\Queries;

use App\Data\Applications\PollIntervalData;
use App\Data\Pages\ApplicationFormPageData;
use App\Enums\EnvironmentType;

class ApplicationCreateQuery
{
    public function handle(): ApplicationFormPageData
    {
        return new ApplicationFormPageData(
            types: EnvironmentType::options(),
            pollInterval: PollIntervalData::fromConfig(),
            maxEnvironments: config()->integer('horizon-watch.limits.wizard_environments'),
        );
    }
}
