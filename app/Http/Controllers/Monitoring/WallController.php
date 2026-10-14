<?php

namespace App\Http\Controllers\Monitoring;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Queries\WallQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WallController extends Controller
{
    public function __invoke(Request $request, Team $current_team, WallQuery $query): Response
    {
        return Inertia::render('monitoring/Wall', [
            'page' => $query->handle($current_team, $request->user()),
        ]);
    }
}
