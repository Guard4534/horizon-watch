<?php

namespace App\Actions\Teams;

use App\Data\Teams\InviteMemberData;
use App\Enums\MemberVisibility;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\Teams\TeamInvitation as TeamInvitationNotification;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class InviteMember
{
    public function handle(Team $team, User $inviter, InviteMemberData $data): TeamInvitation
    {
        $visibility = $data->role->seesEveryEnvironment() ? MemberVisibility::All : $data->visibility;

        return DB::transaction(function () use ($team, $inviter, $data, $visibility) {
            $invitation = $team->invitations()->make([
                'email' => $data->email,
                'role' => $data->role,
                'visibility' => $visibility,
                'invited_by' => $inviter->id,
                'expires_at' => now()->addDays(TeamInvitation::lifetimeDays()),
            ]);
            $code = $invitation->issueCode();
            $invitation->save();

            if ($visibility === MemberVisibility::Manual) {
                $invitation->environments()->sync($data->environmentIds);
            }

            Notification::route('mail', $invitation->email)
                ->notify((new TeamInvitationNotification($invitation, $code))->locale(App::getLocale()));

            return $invitation;
        });
    }
}
