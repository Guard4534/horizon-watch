<?php

namespace App\Actions\Teams;

use App\Models\TeamInvitation;

class IssueInvitationLink
{
    public function handle(TeamInvitation $invitation): string
    {
        abort_unless($invitation->isPending(), 404);

        $code = $invitation->issueCode();
        $invitation->save();

        return TeamInvitation::urlFor($code);
    }
}
