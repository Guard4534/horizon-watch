<?php

namespace App\Http\Responses\Concerns;

use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

trait RedirectsToCurrentTeam
{
    public function toResponse($request): Response
    {
        return $request->wantsJson()
            ? new JsonResponse(['two_factor' => false], 200)
            : redirect()->intended($this->redirectPathForCurrentTeam($request, Fortify::redirects('login')));
    }

    protected function redirectPathForCurrentTeam(Request $request, string $redirect): string
    {
        $team = $this->currentTeam($request);

        URL::defaults(['current_team' => $team->slug]);

        return "/{$team->slug}{$redirect}";
    }

    protected function currentTeam(Request $request): Team
    {
        $user = $request->user();

        abort_if(! $user, 403);

        $team = $user->currentTeam ?? $user->fallbackTeam();

        abort_if(! $team, 403);

        return $team;
    }
}
