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
    private const int RESOLVED_ALERTS = 5;

    public function __construct(private MonitoringRepository $monitoring) {}

    public function handle(Team $team, string $applicationId): ApplicationDetailPageData
    {
        $application = $this->monitoring->configurableApplication($team, $applicationId) ?? abort(404);

        $environments = array_values(array_filter(
            $this->monitoring->configurableEnvironments($team),
            fn (EnvironmentData $environment) => $environment->applicationId === $application->id,
        ));
        $environmentIds = array_map(fn (EnvironmentData $environment) => $environment->id, $environments);

        $open = array_filter(
            $this->monitoring->openAlerts($team),
            fn (AlertData $alert) => in_array($alert->environmentId, $environmentIds, true),
        );
        $resolved = array_slice($this->monitoring->alerts($team, AlertState::Resolved, $application->id)->alerts, 0, self::RESOLVED_ALERTS);

        $worstEnvironments = array_values(array_filter(
            $environments,
            fn (EnvironmentData $environment) => $environment->watched && $environment->status !== null,
        ));
        usort($worstEnvironments, EnvironmentData::compareBySeverityThenPending(...));

        return new ApplicationDetailPageData(
            application: $application,
            environments: $environments,
            recentAlerts: [...array_values($open), ...$resolved],
            worstStatus: $worstEnvironments[0]->status ?? null,
            thresholds: $this->thresholds($team),
        );
    }

    /**
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
