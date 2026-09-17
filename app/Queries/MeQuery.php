<?php

namespace App\Queries;

use App\Data\Pages\MePageData;
use App\Models\Team;
use App\Models\User;

class MeQuery
{
    public function handle(Team $team, User $viewer): MePageData
    {
        return new MePageData(
            memberCount: $team->members()->count(),
            alertEmails: $viewer->alert_emails,
        );
    }
}
