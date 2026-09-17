<?php

namespace App\Http\Controllers\Monitoring;

use App\Actions\Teams\ChangeMemberRole;
use App\Actions\Teams\ChangeMemberVisibility;
use App\Actions\Teams\RemoveMember;
use App\Data\Teams\UpdateMemberData;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\User;
use App\Queries\MembersQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class MemberController extends Controller
{
    /**
     * Show the organization's members, its pending invitations and what
     * each role may do.
     *
     * Known limitation of this view: the "Last seen" column the mockup
     * drew is always an em dash. The starter kit records no last-seen
     * timestamp and adding a column is out of phase 2's scope; the column
     * stays so the table keeps the shape it will have once there is
     * something to put in it.
     */
    public function index(Request $request, Team $current_team, MembersQuery $query): Response
    {
        return Inertia::render('monitoring/Members', [
            'page' => $query->handle($current_team, $request->user()),
        ]);
    }

    /**
     * Change a member's role, their visibility, or both.
     *
     * A {user} who is not a member of this organization is a 404, raised by
     * whichever action runs: both open on the membership with
     * firstOrFail(). Kept there rather than here so the starter kit's own
     * member routes cannot answer differently.
     */
    public function update(
        Request $request,
        Team $current_team,
        User $user,
        UpdateMemberData $data,
        ChangeMemberRole $changeMemberRole,
        ChangeMemberVisibility $changeMemberVisibility,
    ): RedirectResponse {
        Gate::authorize('updateMember', [$current_team, $user]);

        if ($data->role !== null) {
            $changeMemberRole->handle($current_team, $request->user(), $user, $data->role);
        }

        if ($data->visibility !== null) {
            $changeMemberVisibility->handle($current_team, $user, $data->visibility, $data->environmentIds);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Member updated.')]);

        return back();
    }

    /**
     * Remove a member from the organization.
     */
    public function destroy(
        Request $request,
        Team $current_team,
        User $user,
        RemoveMember $removeMember,
    ): RedirectResponse {
        Gate::authorize('removeMember', [$current_team, $user]);

        $removeMember->handle($current_team, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Member removed.')]);

        // An admin is allowed to remove themselves; back() would then land
        // on a page of an organization they no longer belong to, which
        // EnsureTeamMembership answers with a 403.
        return $request->user()?->is($user)
            ? to_route('home')
            : back();
    }
}
