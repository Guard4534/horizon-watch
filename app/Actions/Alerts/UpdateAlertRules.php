<?php

namespace App\Actions\Alerts;

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

                $team->alertRules()->updateOrCreate($key, [
                    'threshold' => $rule->threshold,
                    'severity' => $rule->severity,
                    'notify_email' => $rule->notifyByEmail,
                    'enabled' => $rule->enabled,
                ]);
            }
        });
    }
}
