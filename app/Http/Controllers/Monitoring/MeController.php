<?php

namespace App\Http\Controllers\Monitoring;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Queries\MeQuery;
use Inertia\Inertia;
use Inertia\Response;

class MeController extends Controller
{
    public function __invoke(Team $current_team, MeQuery $query): Response
    {
        return Inertia::render('monitoring/Me', [
            'page' => $query->handle($current_team),
        ]);
    }
}
