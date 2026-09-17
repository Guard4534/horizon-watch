<?php

namespace App\Http\Controllers\Teams;

use App\Actions\Teams\ChangeMemberRole;
use App\Actions\Teams\RemoveMember;
use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\UpdateTeamMemberRequest;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class TeamMemberController extends Controller
{
    public function update(
        UpdateTeamMemberRequest $request,
        Team $team,
        User $user,
        ChangeMemberRole $changeMemberRole,
    ): RedirectResponse {
        Gate::authorize('updateMember', [$team, $user]);

        $changeMemberRole->handle(
            $team,
            $request->user(),
            $user,
            TeamRole::from($request->validated('role')),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Member role updated.')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }

    public function destroy(
        Request $request,
        Team $team,
        User $user,
        RemoveMember $removeMember,
    ): RedirectResponse {
        Gate::authorize('removeMember', [$team, $user]);

        $removeMember->handle($team, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Member removed.')]);

        return $request->user()?->is($user)
            ? to_route('home')
            : to_route('teams.edit', ['team' => $team->slug]);
    }
}
