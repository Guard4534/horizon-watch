<?php

namespace App\Queries;

use App\Data\Monitoring\ApplicationData;
use App\Data\Monitoring\EnvironmentData;
use App\Data\Pages\ApplicationGroupData;
use App\Data\Pages\ApplicationListPageData;
use App\Models\Team;
use App\Monitoring\MonitoringRepository;

class ApplicationListQuery
{
    public function __construct(private MonitoringRepository $monitoring) {}

    public function handle(Team $team): ApplicationListPageData
    {
        $environments = $this->monitoring->environments($team);

        $groups = array_map(function (ApplicationData $application) use ($environments) {
            $own = array_values(array_filter($environments, fn (EnvironmentData $environment) => $environment->applicationId === $application->id));

            return new ApplicationGroupData(
                application: $application,
                environments: $own,
                triageCount: count(array_filter($own, fn (EnvironmentData $environment) => ! $environment->status->isHealthy())),
            );
        }, $this->monitoring->applications($team));

        return new ApplicationListPageData($groups, count($environments));
    }
}
