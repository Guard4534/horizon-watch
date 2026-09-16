<?php

namespace App\Http\Controllers\Teams;

use App\Actions\Teams\CreateTeam;
use App\Actions\Teams\RemoveMember;
use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\DeleteTeamRequest;
use App\Http\Requests\Teams\SaveTeamRequest;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class TeamController extends Controller
{
    /**
     * Display a listing of the user's teams.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('teams/Index', [
            'teams' => $user->toUserTeams(includeCurrent: true),
        ]);
    }

    /**
     * Store a newly created team.
     */
    public function store(SaveTeamRequest $request, CreateTeam $createTeam): RedirectResponse
    {
        $team = $createTeam->handle($request->user(), $request->validated('name'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Team created.')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }

    /**
     * Show the team edit page.
     */
    public function edit(Request $request, Team $team): Response
    {
        $user = $request->user();

        return Inertia::render('teams/Edit', [
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
                'slug' => $team->slug,
                'isPersonal' => $team->is_personal,
            ],
            'members' => $team->members()->get()->map(function (User $member) {
                /** @var Membership $membership */
                $membership = $member->getRelation('pivot');

                return [
                    'id' => $member->id,
                    'name' => $member->name,
                    'email' => $member->email,
                    'avatar' => $member->avatar ?? null,
                    'role' => $membership->role->value,
                    'role_label' => User::roleLabel($membership->role),
                ];
            }),
            // A count, not a list. This route is gated by team membership
            // with no minimum role, so a viewer reads these props out of
            // the page source; who has been invited is an admin's business
            // (MembersQuery withholds the same list without canInvite), and
            // the page has had nothing but a count to draw since the
            // read-only table moved to the Members view.
            //
            // pending(), not whereNull('accepted_at'): that also excludes
            // revoked and expired rows, so this number and the Members view
            // it links to cannot disagree.
            'pendingInvitationCount' => $team->invitations()->pending()->count(),
            'permissions' => $user->toTeamPermissions($team),
            // assignable() stays the source of which roles can be picked;
            // the label comes from the one function that builds a role tag,
            // so the dropdown and the badge next to it read the same.
            'availableRoles' => array_map(
                fn (array $option) => [
                    'value' => $option['value'],
                    'label' => User::roleLabel(TeamRole::from($option['value'])),
                ],
                TeamRole::assignable(),
            ),
        ]);
    }

    /**
     * Update the specified team.
     */
    public function update(SaveTeamRequest $request, Team $team): RedirectResponse
    {
        Gate::authorize('update', $team);

        $team = DB::transaction(function () use ($request, $team) {
            $team = Team::whereKey($team->id)->lockForUpdate()->firstOrFail();

            $team->update(['name' => $request->validated('name')]);

            return $team;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Team updated.')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }

    /**
     * Switch the user's current team.
     */
    public function switch(Request $request, Team $team): RedirectResponse
    {
        abort_unless($request->user()->belongsToTeam($team), 403);

        $request->user()->switchTeam($team);

        return back();
    }

    /**
     * Leave the specified team.
     */
    public function leave(Request $request, Team $team, RemoveMember $removeMember): RedirectResponse
    {
        Gate::authorize('leave', $team);

        $user = $request->user();

        // Through the action, not inline: leaving is a membership removal
        // like any other, and the inline version forgot the member's
        // environment_user grants.
        $removeMember->handle($team, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('You left the team ":name"', ['name' => $team->name])]);

        return to_route('teams.index');
    }

    /**
     * Delete the specified team.
     */
    public function destroy(DeleteTeamRequest $request, Team $team): RedirectResponse
    {
        $user = $request->user();
        $fallbackTeam = $user->isCurrentTeam($team)
            ? $user->fallbackTeam($team)
            : null;

        DB::transaction(function () use ($user, $team) {
            User::where('current_team_id', $team->id)
                ->where('id', '!=', $user->id)
                ->each(fn (User $affectedUser) => $affectedUser->switchTeam($affectedUser->personalTeam()));

            $team->invitations()->delete();
            $team->memberships()->delete();

            // Before the team row, and on purpose. Team uses the starter
            // kit's SoftDeletes, so $team->delete() is an UPDATE and the
            // cascadeOnDelete() on applications.team_id never fires: the
            // applications, their environments, the environment_user grants
            // and the still-decryptable basic-auth passwords would all stay
            // in the database, unreferenced by any live team and unreachable
            // through the interface. The spec asks for a cascade and says
            // phase 2 archives nothing; deleting the applications here lets
            // the application → environments → environment_user cascade do
            // the rest, while the team row keeps the kit's restore path.
            $team->applications()->delete();

            $team->delete();
        });

        if ($fallbackTeam) {
            $user->switchTeam($fallbackTeam);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Team deleted.')]);

        return to_route('teams.index');
    }
}
