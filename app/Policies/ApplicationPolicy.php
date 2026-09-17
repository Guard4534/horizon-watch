<?php

namespace App\Policies;

use App\Enums\TeamPermission;
use App\Models\Application;
use App\Models\Team;
use App\Models\User;

class ApplicationPolicy
{
    public function viewAny(User $user, Team $team): bool
    {
        return $user->belongsToTeam($team);
    }

    public function view(User $user, Application $application): bool
    {
        return $user->belongsToTeam($application->team);
    }

    public function create(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, TeamPermission::ManageApplications);
    }

    public function update(User $user, Application $application): bool
    {
        return $user->hasTeamPermission($application->team, TeamPermission::ManageApplications);
    }

    public function delete(User $user, Application $application): bool
    {
        return $user->hasTeamPermission($application->team, TeamPermission::ManageApplications);
    }
}
