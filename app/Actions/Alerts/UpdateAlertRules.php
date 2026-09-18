<?php

namespace App\Actions\Alerts;

use App\Data\Alerts\AlertRuleInputData;
use App\Data\Alerts\AlertRulesInputData;
use App\Models\AlertRule;
use App\Models\Team;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UpdateAlertRules
{
    public function handle(Team $team, string $scope, AlertRulesInputData $data): void
    {
        $scope = Str::lower($scope);

        DB::transaction(function () use ($team, $scope, $data) {
            foreach ($data->rules as $rule) {
                $key = ['scope' => $scope, 'metric' => $rule->metric];

                if ($scope !== AlertRule::ORGANIZATION && $rule->isEmpty()) {
                    $team->alertRules()->where($key)->delete();

                    continue;
                }

                $this->upsert($team, $scope, $rule);
            }
        });
    }

    private function upsert(Team $team, string $scope, AlertRuleInputData $rule): void
    {
        $model = new AlertRule;
        $timestamp = $model->freshTimestamp();
        $values = ['threshold', 'severity', 'notify_email', 'enabled'];

        $row = $model->forceFill([
            'team_id' => $team->id,
            'scope' => $scope,
            'metric' => $rule->metric,
            'threshold' => $rule->threshold,
            'severity' => $rule->severity,
            'notify_email' => $rule->notifyByEmail,
            'enabled' => $rule->enabled,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ])->getAttributes();

        DB::table($model->getTable())->upsert([$row], ['team_id', 'scope', 'metric'], [...$values, 'updated_at']);
    }
}
