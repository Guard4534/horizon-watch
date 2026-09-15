<?php

namespace App\Queries;

use App\Data\Monitoring\AlertData;
use App\Data\Monitoring\AlertRuleData;
use App\Data\Monitoring\RuleScopeData;
use App\Data\Pages\EnvironmentDetailPageData;
use App\Enums\AlertState;
use App\Enums\RuleOrigin;
use App\Enums\SeriesRange;
use App\Models\Team;
use App\Monitoring\MonitoringRepository;

class EnvironmentDetailQuery
{
    public function __construct(private MonitoringRepository $monitoring) {}

    public function handle(Team $team, string $environmentId, SeriesRange $range): EnvironmentDetailPageData
    {
        $environment = $this->monitoring->environment($team, $environmentId) ?? abort(404);

        $scopeIds = array_map(fn (RuleScopeData $scope) => $scope->id, $this->monitoring->ruleScopes($team));
        $rules = $this->monitoring->alertRules($team, in_array($environment->name, $scopeIds, true) ? $environment->name : 'organization');

        $openAlerts = array_filter(
            $this->monitoring->alerts($team, AlertState::Open),
            fn (AlertData $alert) => $alert->environmentId === $environment->id,
        );

        return new EnvironmentDetailPageData(
            environment: $environment,
            openAlert: array_values($openAlerts)[0] ?? null,
            nodes: $this->monitoring->nodes($team, $environment->id),
            queues: $this->monitoring->queues($team, $environment->id),
            failedJobs: $this->monitoring->failedJobs($team, $environment->id),
            longRunningJobs: $this->monitoring->longRunningJobs($team, $environment->id),
            range: $range,
            throughput: $this->monitoring->throughputSeries($team, $environment->id, $range),
            maxWait: $this->monitoring->maxWaitSeries($team, $environment->id, $range),
            rules: $rules,
            overrideCount: count(array_filter($rules, fn (AlertRuleData $rule) => $rule->origin === RuleOrigin::Override)),
        );
    }
}
