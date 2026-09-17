<?php

namespace App\Actions\Teams;

use App\Models\TeamInvitation;
use App\Notifications\Teams\TeamInvitation as TeamInvitationNotification;
use Illuminate\Support\Facades\Notification;

class ResendInvitation
{
    public function handle(TeamInvitation $invitation): TeamInvitation
    {
        abort_unless($invitation->isPending(), 409);

        $invitation->update(['expires_at' => now()->addDays(7)]);

        Notification::route('mail', $invitation->email)
            ->notify(new TeamInvitationNotification($invitation));

        return $invitation;
    }
}
