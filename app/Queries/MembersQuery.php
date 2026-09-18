<?php

namespace App\Queries;

use App\Data\Pages\EnvironmentOptionData;
use App\Data\Pages\MembersPageData;
use App\Data\Pages\MembersPermissionsData;
use App\Data\Pages\PermissionMatrixRowData;
use App\Data\Teams\InvitationData;
use App\Data\Teams\MemberData;
use App\Enums\MemberVisibility;
use App\Enums\TeamPermission;
use App\Enums\TeamRole;
use App\Models\Environment;
use App\Models\Membership;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Monitoring\VisibleEnvironments;
use Illuminate\Support\Facades\Gate;

class MembersQuery
{
    public function __construct(private VisibleEnvironments $visible) {}

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
                canManageMembers: $canManageMembers,
            ),
            matrix: $this->matrix(),
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

        return $team->memberships()
            ->with('user')
            ->get()
            ->sortBy([
                fn (Membership $a, Membership $b) => $b->role->level() <=> $a->role->level(),
                fn (Membership $a, Membership $b) => strcasecmp($a->user->name, $b->user->name),
            ])
            ->map(function (Membership $membership) use ($team, $viewer, $labels) {
                $grantedIds = $labels === [] ? [] : $this->manualEnvironmentIds($team, $membership);

                return new MemberData(
                    id: $membership->user_id,
                    name: $membership->user->name,
                    email: $membership->user->email,
                    initials: $this->initials($membership->user->name),
                    role: $membership->role,
                    roleLabel: User::roleLabel($membership->role),
                    visibility: $membership->visibility,
                    visibilityLabel: $membership->visibility->label(),
                    visibleEnvironmentIds: $grantedIds,
                    visibleEnvironmentNames: array_values(array_filter(array_map(
                        fn (int $id) => $labels[$id] ?? null,
                        $grantedIds,
                    ))),
                    lastSeenAt: null,
                    isOwner: $membership->role === TeamRole::Owner,
                    isSelf: $membership->user_id === $viewer->id,
                );
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, int>
     */
    private function manualEnvironmentIds(Team $team, Membership $membership): array
    {
        if ($membership->visibility !== MemberVisibility::Manual) {
            return [];
        }

        return $this->visible->query($team, $membership->user)
            ->pluck('environments.id')
            ->map(fn (int $id) => $id)
            ->all();
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
                roleLabel: User::roleLabel($invitation->role),
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
            TeamPermission::AddMember => __('Add a member'),
            TeamPermission::UpdateMember => __('Change a role or a visibility'),
            TeamPermission::RemoveMember => __('Remove a member'),
            TeamPermission::CreateInvitation => __('Invite someone'),
            TeamPermission::CancelInvitation => __('Revoke an invitation'),
            TeamPermission::ManageApplications => __('Create and edit applications and environments'),
            TeamPermission::ManageCredentials => __('Manage the credentials'),
            TeamPermission::ManageAlertRules => __('Edit thresholds and recipients'),
            TeamPermission::MuteAlert => __('Mute an alert'),
            TeamPermission::HandleAnomaly => __('Mark an anomaly handled'),
            TeamPermission::TestConnection => __('Test the connection'),
        };
    }

    private function environmentLabel(Environment $environment): string
    {
        return $environment->application->name.' / '.$environment->name;
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($parts === []) {
            return '';
        }

        $initials = mb_substr($parts[0], 0, 1);

        if (count($parts) > 1) {
            $initials .= mb_substr($parts[count($parts) - 1], 0, 1);
        }

        return mb_strtoupper($initials);
    }
}
