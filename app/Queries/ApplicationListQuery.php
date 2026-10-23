<?php

namespace App\Queries;

use App\Data\Monitoring\ApplicationData;
use App\Data\Monitoring\EnvironmentData;
use App\Data\Pages\ApplicationGroupData;
use App\Data\Pages\ApplicationListPageData;
use App\Enums\EnvironmentStatus;
use App\Models\Team;
use App\Models\User;
use App\Monitoring\MonitoringRepository;
use Illuminate\Support\Facades\Gate;

class ApplicationListQuery
{
    public function __construct(private MonitoringRepository $monitoring) {}

    public function handle(Team $team, User $viewer): ApplicationListPageData
    {
        $environments = $this->monitoring->configurableEnvironments($team);

        $groups = array_map(function (ApplicationData $application) use ($environments) {
            $own = array_values(array_filter($environments, fn (EnvironmentData $environment) => $environment->applicationId === $application->id));
            $attention = array_values(array_filter($own, fn (EnvironmentData $environment) => $environment->needsAttention()));

            return new ApplicationGroupData(
                application: $application,
                environments: $own,
                attentionCount: count($attention),
                worstStatus: EnvironmentStatus::worstOf(array_map(fn (EnvironmentData $environment) => $environment->status, $attention)),
            );
        }, $this->monitoring->configurableApplications($team));

        return new ApplicationListPageData(
            groups: $groups,
            environmentCount: count($environments),
            canTransferConfiguration: Gate::forUser($viewer)->allows('transferConfiguration', $team),
        );
    }
}
