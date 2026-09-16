<?php

namespace App\Policies;

use App\Enums\TeamPermission;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;

class TeamPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Team $team): bool
    {
        return $user->belongsToTeam($team);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, TeamPermission::UpdateTeam);
    }

    /**
     * Determine whether the user can leave the team.
     */
    public function leave(User $user, Team $team): bool
    {
        return ! $team->is_personal
            && $user->belongsToTeam($team)
            && ! $user->ownsTeam($team);
    }

    /**
     * Determine whether the user can add a member to the team.
     */
    public function addMember(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, TeamPermission::AddMember);
    }

    /**
     * Determine whether the user can update a member's role in the team.
     * The owner's own role is never touched by this action: the owner is
     * whoever created the organization, and the flow to hand ownership to
     * someone else doesn't exist yet, so it's forbidden outright rather
     * than left half-supported.
     */
    public function updateMember(User $user, Team $team, ?User $target = null): bool
    {
        if ($target !== null && $target->teamRole($team) === TeamRole::Owner) {
            return false;
        }

        return $user->hasTeamPermission($team, TeamPermission::UpdateMember);
    }

    /**
     * Determine whether the user can remove a member from the team. The
     * owner can never be removed, not even by themselves (see "leave").
     */
    public function removeMember(User $user, Team $team, ?User $target = null): bool
    {
        if ($target !== null && $target->teamRole($team) === TeamRole::Owner) {
            return false;
        }

        return $user->hasTeamPermission($team, TeamPermission::RemoveMember);
    }

    /**
     * Determine whether the user can invite members to the team.
     */
    public function inviteMember(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, TeamPermission::CreateInvitation);
    }

    /**
     * Determine whether the user can cancel invitations.
     */
    public function cancelInvitation(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, TeamPermission::CancelInvitation);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Team $team): bool
    {
        return ! $team->is_personal && $user->hasTeamPermission($team, TeamPermission::DeleteTeam);
    }

    /**
     * Determine whether the user can manage the team's alert rules
     * (thresholds and recipients). Unused until phase 4.
     */
    public function manageAlertRules(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, TeamPermission::ManageAlertRules);
    }

    /**
     * Determine whether the user can mute an alert. Unused until phase 4.
     */
    public function muteAlert(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, TeamPermission::MuteAlert);
    }

    /**
     * Determine whether the user can mark an anomaly as handled. Unused
     * until phase 4.
     */
    public function handleAnomaly(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, TeamPermission::HandleAnomaly);
    }
}
