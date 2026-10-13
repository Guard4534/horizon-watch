<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user === null) {
            return to_route('login');
        }

        $team = $user->currentOrFallbackTeam();

        return $team
            ? to_route('wall', ['current_team' => $team->slug])
            : to_route('teams.index');
    }
}
