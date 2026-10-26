<?php

namespace App\Policies;

use App\Enums\MaintenanceScope;
use App\Enums\TeamPermission;
use App\Models\Application;
use App\Models\Environment;
use App\Models\MaintenanceWindow;
use App\Models\Team;
use App\Models\User;
use App\Monitoring\VisibleEnvironments;

class MaintenanceWindowPolicy
{
    public function __construct(private readonly VisibleEnvironments $visible) {}

    public function create(User $user, Team $team, Application|Environment|null $target = null): bool
    {
        if (! $user->hasTeamPermission($team, TeamPermission::MuteAlert)) {
            return false;
        }

        return $target instanceof Environment
            ? $target->team_id === $team->id && $this->visible->sees($team, $user, $target->id)
            : ($target === null || $target->team_id === $team->id) && $this->visible->seesEverything($team, $user);
    }

    public function delete(User $user, MaintenanceWindow $window): bool
    {
        return $this->create($user, $window->team, match ($window->scope) {
            MaintenanceScope::Organization => null,
            MaintenanceScope::Application => $window->application,
            MaintenanceScope::Environment => $window->environment,
        });
    }
}
