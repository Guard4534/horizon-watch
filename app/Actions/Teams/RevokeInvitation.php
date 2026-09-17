<?php

namespace App\Actions\Teams;

use App\Models\TeamInvitation;

class RevokeInvitation
{
    public function handle(TeamInvitation $invitation): void
    {
        abort_unless($invitation->isPending(), 409);

        $invitation->update(['revoked_at' => now()]);
    }
}
