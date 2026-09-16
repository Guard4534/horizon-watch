<?php

namespace App\Actions\Teams;

use App\Models\TeamInvitation;

class RevokeInvitation
{
    /**
     * Mark the invitation revoked rather than deleting it: the invitation
     * page needs to say "revoked", not 404 as if the code never existed.
     */
    public function handle(TeamInvitation $invitation): void
    {
        abort_unless($invitation->isPending(), 409);

        $invitation->update(['revoked_at' => now()]);
    }
}
