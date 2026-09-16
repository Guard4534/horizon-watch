<?php

namespace App\Http\Controllers\Teams;

use App\Actions\Teams\InviteMember;
use App\Actions\Teams\ResendInvitation;
use App\Actions\Teams\RevokeInvitation;
use App\Data\Teams\InviteMemberData;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\TeamInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class TeamInvitationController extends Controller
{
    /**
     * Store a newly created invitation.
     */
    public function store(Request $request, Team $current_team, InviteMemberData $data, InviteMember $inviteMember): RedirectResponse
    {
        Gate::authorize('inviteMember', $current_team);

        $inviteMember->handle($current_team, $request->user(), $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation sent.')]);

        return back();
    }

    /**
     * Resend the specified invitation: same code, new expiry.
     */
    public function resend(Team $current_team, TeamInvitation $invitation, ResendInvitation $resendInvitation): RedirectResponse
    {
        $this->ensureBelongsToTeam($invitation, $current_team);
        Gate::authorize('inviteMember', $current_team);

        $resendInvitation->handle($invitation);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation resent.')]);

        return back();
    }

    /**
     * Revoke the specified invitation.
     */
    public function destroy(Team $current_team, TeamInvitation $invitation, RevokeInvitation $revokeInvitation): RedirectResponse
    {
        $this->ensureBelongsToTeam($invitation, $current_team);
        Gate::authorize('cancelInvitation', $current_team);

        $revokeInvitation->handle($invitation);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation revoked.')]);

        return back();
    }

    /**
     * An invitation resolved by id alone doesn't know which organization it
     * belongs to: without this check, an admin of one team could act on
     * another team's invitation just by counting up.
     */
    private function ensureBelongsToTeam(TeamInvitation $invitation, Team $team): void
    {
        abort_unless($invitation->team_id === $team->id, 404);
    }
}
