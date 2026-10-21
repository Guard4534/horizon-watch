<?php

namespace App\Queries;

use App\Data\Pages\EnvironmentOptionData;
use App\Data\Pages\MembersPageData;
use App\Data\Pages\MembersPermissionsData;
use App\Data\Pages\PermissionMatrixRowData;
use App\Data\Teams\InvitationData;
use App\Data\Teams\MemberData;
use App\Data\Teams\RoleOptionData;
use App\Data\Teams\VisibilityOptionData;
use App\Enums\MemberVisibility;
use App\Enums\TeamPermission;
use App\Enums\TeamRole;
use App\Models\Environment;
use App\Models\Membership;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class MembersQuery
{
    public function handle(Team $team, User $viewer): MembersPageData
    {
        $gate = Gate::forUser($viewer);

        $canInvite = $gate->allows('inviteMember', $team);
        $canManageMembers = $gate->allows('updateMember', $team)
            && $gate->allows('removeMember', $team);

        $environments = $canInvite || $canManageMembers ? $this->environments($team) : [];

        return new MembersPageData(
            members: $this->members($team, $viewer, $canManageMembers ? $environments : []),
            invitations: $canInvite ? $this->invitations($team) : [],
            environments: $environments,
            permissions: new MembersPermissionsData(
                canInvite: $canInvite,
                canCopyInvitationLinks: $gate->allows('copyInvitationLink', $team),
                canManageMembers: $canManageMembers,
                canTransferOwnership: $gate->allows('transferOwnership', $team),
            ),
            matrix: $this->matrix(),
            roles: array_map(RoleOptionData::of(...), TeamRole::cases()),
            assignableRoles: array_map(RoleOptionData::of(...), TeamRole::assignable()),
            visibilities: array_map(VisibilityOptionData::of(...), MemberVisibility::cases()),
            invitationExpiresDays: TeamInvitation::lifetimeDays(),
        );
    }

    /**
     * @param  array<int, EnvironmentOptionData>  $environments
     * @return array<int, MemberData>
     */
    private function members(Team $team, User $viewer, array $environments): array
    {
        $labels = [];

        foreach ($environments as $option) {
            $labels[$option->id] = $option->name;
        }

        $memberships = $team->memberships()
            ->with('user')
            ->get()
            ->sortBy([
                fn (Membership $a, Membership $b) => $b->role->level() <=> $a->role->level(),
                fn (Membership $a, Membership $b) => strcasecmp($a->user->name, $b->user->name),
            ]);

        $granted = $labels === [] ? [] : $this->grantedEnvironmentIds($memberships, array_keys($labels));

        return $memberships
            ->map(function (Membership $membership) use ($viewer, $labels, $granted) {
                $grantedIds = array_values(array_filter(
                    array_keys($labels),
                    fn (int $id) => isset($granted[$membership->user_id][$id]),
                ));

                return new MemberData(
                    id: $membership->user_id,
                    name: $membership->user->name,
                    email: $membership->user->email,
                    role: $membership->role,
                    roleLabel: $membership->role->label(),
                    visibility: $membership->visibility,
                    visibilityLabel: $membership->visibility->label(),
                    visibleEnvironmentIds: $grantedIds,
                    visibleEnvironmentNames: array_map(fn (int $id) => $labels[$id], $grantedIds),
                    isOwner: $membership->role === TeamRole::Owner,
                    isSelf: $membership->user_id === $viewer->id,
                );
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Membership>  $memberships
     * @param  array<int, int>  $environmentIds
     * @return array<int, array<int, true>>
     */
    private function grantedEnvironmentIds(Collection $memberships, array $environmentIds): array
    {
        $manualUserIds = $memberships
            ->filter(fn (Membership $membership) => $membership->visibility === MemberVisibility::Manual)
            ->map(fn (Membership $membership) => $membership->user_id)
            ->all();

        if ($manualUserIds === [] || $environmentIds === []) {
            return [];
        }

        $granted = [];

        foreach (DB::table('environment_user')
            ->whereIn('user_id', $manualUserIds)
            ->whereIn('environment_id', $environmentIds)
            ->get(['user_id', 'environment_id']) as $row) {
            $granted[(int) $row->user_id][(int) $row->environment_id] = true;
        }

        return $granted;
    }

    /**
     * @return array<int, InvitationData>
     */
    private function invitations(Team $team): array
    {
        return $team->invitations()
            ->pending()
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (TeamInvitation $invitation) => new InvitationData(
                id: $invitation->id,
                email: $invitation->email,
                role: $invitation->role,
                roleLabel: $invitation->role->label(),
                visibility: $invitation->visibility,
                visibilityLabel: $invitation->visibility->label(),
                invitedAt: $invitation->created_at?->toIso8601String(),
                expiresAt: $invitation->expires_at?->toIso8601String(),
            ))
            ->values()
            ->all();
    }

    /**
     * @return array<int, EnvironmentOptionData>
     */
    private function environments(Team $team): array
    {
        return $team->environments()
            ->with('application')
            ->get()
            ->sortBy(fn (Environment $environment) => $this->environmentLabel($environment))
            ->map(fn (Environment $environment) => new EnvironmentOptionData(
                id: $environment->id,
                name: $this->environmentLabel($environment),
                type: $environment->type,
            ))
            ->values()
            ->all();
    }

    /**
     * @return array<int, PermissionMatrixRowData>
     */
    private function matrix(): array
    {
        return array_map(fn (TeamPermission $permission) => new PermissionMatrixRowData(
            permission: $permission->value,
            label: $this->permissionLabel($permission),
            owner: TeamRole::Owner->hasPermission($permission),
            admin: TeamRole::Admin->hasPermission($permission),
            member: TeamRole::Member->hasPermission($permission),
            viewer: TeamRole::Viewer->hasPermission($permission),
        ), TeamPermission::cases());
    }

    private function permissionLabel(TeamPermission $permission): string
    {
        return match ($permission) {
            TeamPermission::UpdateTeam => __('Rename the organization'),
            TeamPermission::DeleteTeam => __('Delete the organization'),
            TeamPermission::UpdateMember => __('Change a role or a visibility'),
            TeamPermission::RemoveMember => __('Remove a member'),
            TeamPermission::CreateInvitation => __('Invite someone'),
            TeamPermission::CancelInvitation => __('Revoke an invitation'),
            TeamPermission::ManageApplications => __('Create and edit applications and environments'),
            TeamPermission::ManageCredentials => __('Manage the credentials'),
            TeamPermission::ManageAlertRules => __('Edit thresholds and notifications'),
            TeamPermission::MuteAlert => __('Mute an alert or plan maintenance'),
            TeamPermission::HandleAlert => __('Mark an alert as handled'),
            TeamPermission::TestConnection => __('Test the connection'),
        };
    }

    private function environmentLabel(Environment $environment): string
    {
        return $environment->application->name.' / '.$environment->name;
    }
}
