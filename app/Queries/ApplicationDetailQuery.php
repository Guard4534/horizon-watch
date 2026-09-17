<?php

namespace App\Queries;

use App\Data\Monitoring\AlertData;
use App\Data\Monitoring\AlertRuleData;
use App\Data\Monitoring\EnvironmentData;
use App\Data\Pages\ApplicationDetailPageData;
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
            thresholds: $this->thresholds($team),
        );
    }

    /**
     * The organization scope: its overrides are invented until phase 4, the
     * same ruling as the environment page.
     *
     * @return array<string, float>
     */
    private function thresholds(Team $team): array
    {
        $rules = $this->monitoring->alertRules($team, 'organization');

        return array_combine(
            array_map(fn (AlertRuleData $rule) => $rule->metric->value, $rules),
            array_map(fn (AlertRuleData $rule) => $rule->threshold, $rules),
        );
    }
}
