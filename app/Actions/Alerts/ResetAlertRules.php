<?php

namespace App\Actions\Alerts;

use App\Models\Team;
use Illuminate\Support\Str;

class ResetAlertRules
{
    public function handle(Team $team, string $scope): void
    {
        $team->alertRules()
            ->where('scope', Str::lower($scope))
            ->delete();
    }
}
