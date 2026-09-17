<?php

namespace App\Actions\Teams;

use App\Models\TeamInvitation;

class DeclineInvitation
{
    public function handle(TeamInvitation $invitation): void
    {
        $invitation->delete();
    }
}
