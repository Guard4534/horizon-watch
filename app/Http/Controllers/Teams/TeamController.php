<?php

namespace App\Http\Controllers\Teams;

use App\Actions\Teams\CreateTeam;
use App\Actions\Teams\DeleteTeam;
use App\Actions\Teams\RemoveMember;
use App\Actions\Teams\RenameTeam;
use App\Data\Applications\ConfirmByNameData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\SaveTeamRequest;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class TeamController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('teams/Index', [
            'teams' => $request->user()->toUserTeams(includeCurrent: true),
        ]);
    }

    public function store(SaveTeamRequest $request, CreateTeam $createTeam): RedirectResponse
    {
        $createTeam->handle($request->user(), $request->validated('name'));

        $this->success(__('Team created.'));

        return to_route('teams.index');
    }

    public function update(SaveTeamRequest $request, Team $team, RenameTeam $renameTeam): RedirectResponse
    {
        Gate::authorize('update', $team);

        $renameTeam->handle($team, $request->validated('name'));

        $this->success(__('Team updated.'));

        return to_route('teams.index');
    }

    public function switch(Request $request, Team $team): RedirectResponse
    {
        abort_unless($request->user()->belongsToTeam($team), 403);

        $request->user()->switchTeam($team);

        return back();
    }

    public function leave(Request $request, Team $team, RemoveMember $removeMember): RedirectResponse
    {
        Gate::authorize('leave', $team);

        $removeMember->handle($team, $request->user());

        $this->success(__('You left the team ":name"', ['name' => $team->name]));

        return to_route('teams.index');
    }

    public function destroy(Request $request, Team $team, ConfirmByNameData $data, DeleteTeam $deleteTeam): RedirectResponse
    {
        Gate::authorize('delete', $team);

        $deleteTeam->handle($team, $request->user(), $data);

        $this->success(__('Team deleted.'));

        return to_route('teams.index');
    }
}
