<?php

namespace App\Http\Controllers\Teams;

use App\Actions\Teams\InviteMember;
use App\Actions\Teams\IssueInvitationLink;
use App\Actions\Teams\ResendInvitation;
use App\Actions\Teams\RevokeInvitation;
use App\Data\Teams\InviteMemberData;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\TeamInvitation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class TeamInvitationController extends Controller
{
    public function store(Request $request, Team $current_team, InviteMemberData $data, InviteMember $inviteMember): RedirectResponse
    {
        Gate::authorize('inviteMember', $current_team);

        $inviteMember->handle($current_team, $request->user(), $data);

        $this->success(__('Invitation sent.'));

        return back();
    }

    public function resend(Team $current_team, TeamInvitation $invitation, ResendInvitation $resendInvitation): RedirectResponse
    {
        $this->ensureBelongsToTeam($invitation, $current_team);
        Gate::authorize('inviteMember', $current_team);

        $resendInvitation->handle($invitation);

        $this->success(__('Invitation resent.'));

        return back();
    }

    public function link(Team $current_team, TeamInvitation $invitation, IssueInvitationLink $issueInvitationLink): JsonResponse
    {
        $this->ensureBelongsToTeam($invitation, $current_team);
        Gate::authorize('copyInvitationLink', $current_team);

        return response()->json(['url' => $issueInvitationLink->handle($invitation)], 200, ['Cache-Control' => 'no-store']);
    }

    public function destroy(Team $current_team, TeamInvitation $invitation, RevokeInvitation $revokeInvitation): RedirectResponse
    {
        $this->ensureBelongsToTeam($invitation, $current_team);
        Gate::authorize('cancelInvitation', $current_team);

        $revokeInvitation->handle($invitation);

        $this->success(__('Invitation revoked.'));

        return back();
    }

    private function ensureBelongsToTeam(TeamInvitation $invitation, Team $team): void
    {
        abort_unless($invitation->team_id === $team->id, 404);
    }
}
