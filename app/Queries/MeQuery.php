<?php

namespace App\Queries;

use App\Data\Pages\MePageData;
use App\Models\Team;

class MeQuery
{
    public function handle(Team $team): MePageData
    {
        return new MePageData(
            memberCount: $team->members()->count(),
        );
    }
}
