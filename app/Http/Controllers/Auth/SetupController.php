<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Setup\CompleteSetup;
use App\Data\Auth\SetupData;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class SetupController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('auth/Setup');
    }

    public function store(SetupData $data, Request $request, CompleteSetup $completeSetup): RedirectResponse
    {
        $user = $completeSetup->handle($data);

        Auth::login($user);
        $request->session()->regenerate();

        return to_route('wall', ['current_team' => $user->currentTeam?->slug]);
    }
}
