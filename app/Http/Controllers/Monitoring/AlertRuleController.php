<?php

namespace App\Http\Controllers\Monitoring;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Queries\AlertRulesQuery;
use Inertia\Inertia;
use Inertia\Response;

class AlertRuleController extends Controller
{
    public function index(Team $current_team, AlertRulesQuery $query, string $scope = 'organization'): Response
    {
        return Inertia::render('monitoring/alert-rules/Index', [
            'page' => $query->handle($current_team, $scope),
        ]);
    }
}
