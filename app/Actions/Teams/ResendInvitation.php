<?php

namespace App\Actions\Teams;

use App\Models\TeamInvitation;
use App\Notifications\Teams\TeamInvitation as TeamInvitationNotification;
use Illuminate\Support\Facades\Notification;

class ResendInvitation
{
    /**
     * Push the expiry out another 7 days and send the email again. The
     * code stays the same — anyone who already opened the link keeps a
     * working one.
     */
    public function handle(TeamInvitation $invitation): TeamInvitation
    {
        abort_unless($invitation->isPending(), 409);

        $invitation->update(['expires_at' => now()->addDays(7)]);

        Notification::route('mail', $invitation->email)
            ->notify(new TeamInvitationNotification($invitation));

        return $invitation;
    }
}
