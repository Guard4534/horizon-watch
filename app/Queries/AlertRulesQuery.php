<?php

namespace App\Queries;

use App\Data\Monitoring\RuleScopeData;
use App\Data\Pages\AlertRulesPageData;
use App\Models\Team;
use App\Monitoring\MonitoringRepository;

class AlertRulesQuery
{
    public function __construct(private MonitoringRepository $monitoring) {}

    public function handle(Team $team, string $scope): AlertRulesPageData
    {
        $scopes = $this->monitoring->ruleScopes($team);

        abort_unless(in_array($scope, array_map(fn (RuleScopeData $item) => $item->id, $scopes), true), 404);

        return new AlertRulesPageData(
            scopes: $scopes,
            scope: $scope,
            rules: $this->monitoring->alertRules($team, $scope),
            notifications: $this->monitoring->notificationSettings($team),
        );
    }
}
