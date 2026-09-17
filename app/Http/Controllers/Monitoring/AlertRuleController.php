<?php

namespace App\Http\Controllers\Monitoring;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Queries\AlertRulesQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AlertRuleController extends Controller
{
    public function index(Request $request, Team $current_team, AlertRulesQuery $query, string $scope = 'organization'): Response
    {
        return Inertia::render('monitoring/alert-rules/Index', [
            'page' => $query->handle($current_team, $request->user(), $scope),
        ]);
    }
}
