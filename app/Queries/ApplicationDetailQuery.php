<?php

namespace App\Queries;

use App\Data\Monitoring\AlertData;
use App\Data\Monitoring\EnvironmentData;
use App\Data\Pages\ApplicationDetailPageData;
use App\Enums\AlertRuleMetric;
use App\Enums\AlertState;
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

        // Only rows that say something: unwatched rows and environments
        // without a reading carry no status.
        $worstEnvironments = array_values(array_filter(
            $environments,
            fn (EnvironmentData $environment) => $environment->watched && $environment->status !== null,
        ));
        usort($worstEnvironments, EnvironmentData::compareBySeverityThenPending(...));

        return new ApplicationDetailPageData(
            application: $application,
            // The cards draw each environment's pending trend, already loaded
            // with the list in one query: no per-environment series here.
            environments: $environments,
            recentAlerts: array_slice(array_values($alerts), 0, 3),
            worstStatus: $worstEnvironments[0]->status ?? null,
            // Thresholds are the defaults until phase 4 lets them be edited.
            failedPerHourThreshold: AlertRuleMetric::JobsFailedPerHour->defaultThreshold(),
        );
    }
}
