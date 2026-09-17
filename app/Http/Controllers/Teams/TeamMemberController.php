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

/**
 * The starter kit's own member routes, kept alongside the phase 2 Members
 * view (see MemberController). Both go through the same two actions on
 * purpose: when these methods wrote the membership themselves, the rule
 * that an admin may not demote the last admin besides the owner held on
 * one route and not the other, and removing someone here left their
 * environment_user grants behind.
 */
class TeamMemberController extends Controller
{
    /**
     * Update the specified team member's role.
     */
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

    /**
     * Remove the specified team member.
     */
    public function destroy(
        Request $request,
        Team $team,
        User $user,
        RemoveMember $removeMember,
    ): RedirectResponse {
        Gate::authorize('removeMember', [$team, $user]);

        $removeMember->handle($team, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Member removed.')]);

        // Removing yourself makes this page a 403 on the way back.
        return $request->user()?->is($user)
            ? to_route('home')
            : to_route('teams.edit', ['team' => $team->slug]);
    }
}
