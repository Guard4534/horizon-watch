<?php

namespace App\Http\Controllers\Monitoring;

use App\Enums\SeriesRange;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Queries\EnvironmentDetailQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EnvironmentController extends Controller
{
    public function show(Request $request, Team $current_team, string $environment, EnvironmentDetailQuery $query): Response
    {
        return Inertia::render('monitoring/environments/Show', [
            'page' => $query->handle($current_team, $environment, $request->enum('range', SeriesRange::class) ?? SeriesRange::ThreeHours),
        ]);
    }
}
