<?php

namespace App\Policies;

use App\Enums\OrganizationCreation;
use App\Enums\TeamPermission;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Support\MailStatus;

class TeamPolicy
{
    public function create(User $user): bool
    {
        return $user->is_instance_admin || OrganizationCreation::configured() === OrganizationCreation::Everyone;
    }

    public function update(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, TeamPermission::UpdateTeam);
    }

    public function leave(User $user, Team $team): bool
    {
        return $user->belongsToTeam($team) && ! $user->ownsTeam($team);
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

    public function copyInvitationLink(User $user, Team $team): bool
    {
        return $this->inviteMember($user, $team)
            && ($user->is_instance_admin || ! MailStatus::isConfigured());
    }

    public function cancelInvitation(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, TeamPermission::CancelInvitation);
    }

    public function transferOwnership(User $user, Team $team, ?User $target = null): bool
    {
        if ($target !== null && $target->is($user)) {
            return false;
        }

        return $user->ownsTeam($team);
    }

    public function delete(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, TeamPermission::DeleteTeam);
    }

    public function manageAlertRules(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, TeamPermission::ManageAlertRules);
    }

    public function muteAlert(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, TeamPermission::MuteAlert);
    }

    public function handleAlert(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, TeamPermission::HandleAlert);
    }
}
