<?php

namespace App\Queries;

use App\Data\Monitoring\EnvironmentData;
use App\Data\Pages\ApplicationDetailPageData;
use App\Models\Team;
use App\Monitoring\MonitoringRepository;

class ApplicationDetailQuery
{
    public function __construct(private MonitoringRepository $monitoring) {}

    public function handle(Team $team, string $applicationId): ApplicationDetailPageData
    {
        $application = $this->monitoring->configurableApplication($team, $applicationId) ?? abort(404);

        $environments = array_values(array_filter(
            $this->monitoring->configurableEnvironments($team),
            fn (EnvironmentData $environment) => $environment->applicationId === $application->id,
        ));
        $resolved = $this->monitoring->latestResolvedAlerts($team, $application->id, config()->integer('horizon-watch.pages.resolved_alerts'));

        $worstEnvironments = array_values(array_filter(
            $environments,
            fn (EnvironmentData $environment) => $environment->watched && $environment->status !== null,
        ));
        usort($worstEnvironments, EnvironmentData::compareBySeverityThenPending(...));

        return new ApplicationDetailPageData(
            application: $application,
            environments: $environments,
            recentAlerts: [...$this->monitoring->liveAlertsOf($team, $application->id), ...$resolved],
            worstStatus: $worstEnvironments[0]->status ?? null,
        );
    }
}
