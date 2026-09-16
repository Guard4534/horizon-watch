<?php

namespace App\Policies;

use App\Enums\TeamPermission;
use App\Models\Application;
use App\Models\Team;
use App\Models\User;

class ApplicationPolicy
{
    /**
     * Determine whether the user can view the team's applications. Reading
     * only requires organization membership; visibility of individual
     * environments is filtered separately by VisibleEnvironments.
     */
    public function viewAny(User $user, Team $team): bool
    {
        return $user->belongsToTeam($team);
    }

    /**
     * Determine whether the user can view the application.
     */
    public function view(User $user, Application $application): bool
    {
        return $user->belongsToTeam($application->team);
    }

    /**
     * Determine whether the user can create applications for the team.
     */
    public function create(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, TeamPermission::ManageApplications);
    }

    /**
     * Determine whether the user can update the application.
     */
    public function update(User $user, Application $application): bool
    {
        return $user->hasTeamPermission($application->team, TeamPermission::ManageApplications);
    }

    /**
     * Determine whether the user can delete the application.
     */
    public function delete(User $user, Application $application): bool
    {
        return $user->hasTeamPermission($application->team, TeamPermission::ManageApplications);
    }
}
