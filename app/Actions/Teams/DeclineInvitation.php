<?php

namespace App\Actions\Teams;

use App\Models\TeamInvitation;

class DeclineInvitation
{
    /**
     * Declining removes the invitation outright, unlike revoking (see
     * RevokeInvitation): the person it was sent to said no, there's
     * nothing left to show them a "declined" state for.
     */
    public function handle(TeamInvitation $invitation): void
    {
        $invitation->delete();
    }
}
