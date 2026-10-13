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

        return $user->currentTeam
            ? to_route('wall', ['current_team' => $user->currentTeam->slug])
            : to_route('teams.index');
    }
}
