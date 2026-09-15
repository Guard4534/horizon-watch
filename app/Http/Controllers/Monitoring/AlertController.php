<?php

namespace App\Http\Controllers\Monitoring;

use App\Enums\AlertState;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Queries\AlertLogQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AlertController extends Controller
{
    public function index(Request $request, Team $current_team, AlertLogQuery $query): Response
    {
        return Inertia::render('monitoring/alerts/Index', [
            'page' => $query->handle($current_team, $request->enum('state', AlertState::class) ?? AlertState::Open),
        ]);
    }
}
