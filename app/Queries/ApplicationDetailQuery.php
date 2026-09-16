<?php

namespace App\Queries;

use App\Data\Monitoring\AlertData;
use App\Data\Monitoring\EnvironmentData;
use App\Data\Pages\ApplicationDetailPageData;
use App\Data\Pages\EnvironmentCardData;
use App\Enums\AlertState;
use App\Enums\SeriesRange;
use App\Models\Team;
use App\Monitoring\MonitoringRepository;

class ApplicationDetailQuery
{
    public function __construct(private MonitoringRepository $monitoring) {}

    public function handle(Team $team, string $applicationId): ApplicationDetailPageData
    {
        // The configuration view, like the list this page is opened from:
        // see ApplicationListQuery::handle(). The alerts below stay the
        // watched ones, so a hidden environment simply brings none.
        $application = $this->monitoring->configurableApplication($team, $applicationId) ?? abort(404);

        $environments = array_values(array_filter(
            $this->monitoring->configurableEnvironments($team),
            fn (EnvironmentData $environment) => $environment->applicationId === $application->id,
        ));
        $environmentIds = array_map(fn (EnvironmentData $environment) => $environment->id, $environments);

        $alerts = array_filter(
            [...$this->monitoring->alerts($team, AlertState::Open), ...$this->monitoring->alerts($team, AlertState::Resolved)],
            fn (AlertData $alert) => in_array($alert->environmentId, $environmentIds, true),
        );

        $worstEnvironments = $environments;
        usort($worstEnvironments, EnvironmentData::compareBySeverityThenPending(...));

        return new ApplicationDetailPageData(
            application: $application,
            cards: array_map(fn (EnvironmentData $environment) => new EnvironmentCardData(
                environment: $environment,
                sparkline: array_slice($this->monitoring->throughputSeries($team, $environment->id, SeriesRange::ThreeHours), -24),
            ), $environments),
            recentAlerts: array_slice(array_values($alerts), 0, 3),
            worstStatus: $worstEnvironments[0]->status ?? null,
        );
    }
}
