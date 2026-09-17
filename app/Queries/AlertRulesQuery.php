<?php

namespace App\Queries;

use App\Data\Monitoring\RuleScopeData;
use App\Data\Pages\AlertRulesPageData;
use App\Data\Pages\NotificationSummaryData;
use App\Models\Team;
use App\Models\User;
use App\Monitoring\MonitoringRepository;
use Illuminate\Support\Facades\Gate;

class AlertRulesQuery
{
    public function __construct(private MonitoringRepository $monitoring) {}

    public function handle(Team $team, User $viewer, string $scope): AlertRulesPageData
    {
        $scopes = $this->monitoring->ruleScopes($team);

        abort_unless(in_array($scope, array_map(fn (RuleScopeData $item) => $item->id, $scopes), true), 404);

        $settings = $this->monitoring->notificationSettings($team);

        return new AlertRulesPageData(
            scopes: $scopes,
            scope: $scope,
            rules: $this->monitoring->alertRules($team, $scope),
            notificationSummary: NotificationSummaryData::of($settings),
            notifications: Gate::forUser($viewer)->allows('manageAlertRules', $team) ? $settings : null,
        );
    }
}
