<?php

namespace App\Http\Controllers\Monitoring;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Queries\ApplicationDetailQuery;
use App\Queries\ApplicationListQuery;
use Inertia\Inertia;
use Inertia\Response;

class ApplicationController extends Controller
{
    public function index(Team $current_team, ApplicationListQuery $query): Response
    {
        return Inertia::render('monitoring/applications/Index', [
            'page' => $query->handle($current_team),
        ]);
    }

    public function show(Team $current_team, string $application, ApplicationDetailQuery $query): Response
    {
        return Inertia::render('monitoring/applications/Show', [
            'page' => $query->handle($current_team, $application),
        ]);
    }
}
