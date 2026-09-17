<?php

namespace App\Actions\Teams;

use App\Data\Teams\InviteMemberData;
use App\Enums\MemberVisibility;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\Teams\TeamInvitation as TeamInvitationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class InviteMember
{
    /**
     * Create an invitation and send it. Environments are only recorded for
     * "manual" visibility: they're copied into environment_user once the
     * invitation is accepted (see AcceptInvitation).
     */
    public function handle(Team $team, User $inviter, InviteMemberData $data): TeamInvitation
    {
        return DB::transaction(function () use ($team, $inviter, $data) {
            $invitation = $team->invitations()->create([
                'email' => $data->email,
                'role' => $data->role,
                'visibility' => $data->visibility,
                'invited_by' => $inviter->id,
                'expires_at' => now()->addDays(7),
            ]);

            if ($data->visibility === MemberVisibility::Manual) {
                $invitation->environments()->sync($data->environmentIds);
            }

            Notification::route('mail', $invitation->email)
                ->notify(new TeamInvitationNotification($invitation));

            return $invitation;
        });
    }
}
