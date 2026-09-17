<?php

namespace App\Policies;

use App\Enums\TeamPermission;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;

class TeamPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Team $team): bool
    {
        return $user->belongsToTeam($team);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, TeamPermission::UpdateTeam);
    }

    public function leave(User $user, Team $team): bool
    {
        return ! $team->is_personal
            && $user->belongsToTeam($team)
            && ! $user->ownsTeam($team);
    }

    public function addMember(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, TeamPermission::AddMember);
    }

    public function updateMember(User $user, Team $team, ?User $target = null): bool
    {
        if ($target !== null && $target->teamRole($team) === TeamRole::Owner) {
            return false;
        }

        return $user->hasTeamPermission($team, TeamPermission::UpdateMember);
    }

    public function removeMember(User $user, Team $team, ?User $target = null): bool
    {
        if ($target !== null && $target->teamRole($team) === TeamRole::Owner) {
            return false;
        }

        return $user->hasTeamPermission($team, TeamPermission::RemoveMember);
    }

    public function inviteMember(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, TeamPermission::CreateInvitation);
    }

    public function cancelInvitation(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, TeamPermission::CancelInvitation);
    }

    public function delete(User $user, Team $team): bool
    {
        return ! $team->is_personal && $user->hasTeamPermission($team, TeamPermission::DeleteTeam);
    }

    public function manageAlertRules(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, TeamPermission::ManageAlertRules);
    }

    public function muteAlert(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, TeamPermission::MuteAlert);
    }

    public function handleAnomaly(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, TeamPermission::HandleAnomaly);
    }
}
