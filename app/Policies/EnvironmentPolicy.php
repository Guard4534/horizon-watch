<?php

namespace App\Policies;

use App\Enums\TeamPermission;
use App\Models\Application;
use App\Models\Environment;
use App\Models\User;

class EnvironmentPolicy
{
    public function view(User $user, Environment $environment): bool
    {
        return $user->belongsToTeam($environment->application->team);
    }

    public function create(User $user, Application $application): bool
    {
        return $user->hasTeamPermission($application->team, TeamPermission::ManageApplications);
    }

    public function update(User $user, Environment $environment): bool
    {
        return $user->hasTeamPermission($environment->application->team, TeamPermission::ManageApplications);
    }

    public function delete(User $user, Environment $environment): bool
    {
        return $user->hasTeamPermission($environment->application->team, TeamPermission::ManageApplications);
    }

    public function manageCredentials(User $user, Environment $environment): bool
    {
        return $user->hasTeamPermission($environment->application->team, TeamPermission::ManageCredentials);
    }

    public function testConnection(User $user, Environment $environment): bool
    {
        return $user->hasTeamPermission($environment->application->team, TeamPermission::TestConnection);
    }
}
