<?php

namespace App\Http\Controllers\Monitoring;

use App\Actions\Alerts\ResetAlertRules;
use App\Actions\Alerts\UpdateAlertRules;
use App\Data\Alerts\AlertRulesInputData;
use App\Data\Monitoring\RuleScopeData;
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
        return Inertia::render('monitoring/alert-rules/Index', [
            'page' => $query->handle($current_team, $request->user(), $scope, $request->session()),
        ]);
    }

    public function update(Team $current_team, string $scope, AlertRulesInputData $data, MonitoringRepository $monitoring, UpdateAlertRules $updateAlertRules): RedirectResponse
    {
        $this->ensureScopeExists($monitoring, $current_team, $scope);

        $updateAlertRules->handle($current_team, $scope, $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Alert rules saved.')]);

        return back();
    }

    public function reset(Team $current_team, string $scope, MonitoringRepository $monitoring, ResetAlertRules $resetAlertRules): RedirectResponse
    {
        $this->ensureScopeExists($monitoring, $current_team, $scope);

        $resetAlertRules->handle($current_team, $scope);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Recommended rules restored.')]);

        return back();
    }

    private function ensureScopeExists(MonitoringRepository $monitoring, Team $team, string $scope): void
    {
        $ids = array_map(fn (RuleScopeData $item) => $item->id, $monitoring->ruleScopes($team));

        abort_unless(in_array($scope, $ids, true), 404);
    }
}
