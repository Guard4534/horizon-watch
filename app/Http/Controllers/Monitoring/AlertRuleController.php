<?php

namespace App\Http\Controllers\Monitoring;

use App\Actions\Alerts\ResetAlertRules;
use App\Actions\Alerts\UpdateAlertRules;
use App\Data\Alerts\AlertRulesInputData;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Monitoring\MonitoringRepository;
use App\Queries\AlertRulesQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AlertRuleController extends Controller
{
    public function index(Request $request, Team $current_team, AlertRulesQuery $query, string $scope = 'organization'): Response
    {
        return AlertRulesQuery::render($query->handle($current_team, $request->user(), $scope, $request->session()));
    }

    public function update(Request $request, Team $current_team, string $scope, MonitoringRepository $monitoring, UpdateAlertRules $updateAlertRules): RedirectResponse
    {
        abort_unless($monitoring->hasRuleScope($current_team, $scope), 404);

        $updateAlertRules->handle($current_team, $scope, AlertRulesInputData::validateAndCreate([
            ...$request->all(),
            AlertRulesInputData::SCOPE => $scope,
        ]));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Alert rules saved.')]);

        return back();
    }

    public function reset(Team $current_team, string $scope, MonitoringRepository $monitoring, ResetAlertRules $resetAlertRules): RedirectResponse
    {
        abort_unless($monitoring->hasRuleScope($current_team, $scope), 404);

        $resetAlertRules->handle($current_team, $scope);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Recommended rules restored.')]);

        return back();
    }
}
