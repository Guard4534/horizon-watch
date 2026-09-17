<?php

namespace App\Policies;

use App\Enums\TeamPermission;
use App\Models\Application;
use App\Models\Environment;
use App\Models\User;

class EnvironmentPolicy
{
    /**
     * Determine whether the user can view the environment. This only checks
     * organization membership: whether this particular environment is
     * visible to the member is decided by VisibleEnvironments, not here.
     */
    public function view(User $user, Environment $environment): bool
    {
        return $user->belongsToTeam($environment->application->team);
    }

    /**
     * Determine whether the user can create an environment for the application.
     */
    public function create(User $user, Application $application): bool
    {
        return $user->hasTeamPermission($application->team, TeamPermission::ManageApplications);
    }

    /**
     * Determine whether the user can update the environment.
     */
    public function update(User $user, Environment $environment): bool
    {
        return $user->hasTeamPermission($environment->application->team, TeamPermission::ManageApplications);
    }

    /**
     * Determine whether the user can delete the environment.
     */
    public function delete(User $user, Environment $environment): bool
    {
        return $user->hasTeamPermission($environment->application->team, TeamPermission::ManageApplications);
    }

    /**
     * Determine whether the user can manage the environment's credentials
     * (basic auth username and password).
     */
    public function manageCredentials(User $user, Environment $environment): bool
    {
        return $user->hasTeamPermission($environment->application->team, TeamPermission::ManageCredentials);
    }

    /**
     * Determine whether the user can test the connection to the environment.
     * Unused until phase 3, kept here so the permission matrix is complete.
     */
    public function testConnection(User $user, Environment $environment): bool
    {
        return $user->hasTeamPermission($environment->application->team, TeamPermission::TestConnection);
    }
}
